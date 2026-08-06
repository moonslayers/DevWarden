<?php

use App\Ai\Tools\Opencode\MarkSessionDoneTool;
use App\Ai\Tools\Opencode\OpencodeAskTool;
use App\Ai\Tools\Opencode\ReactivateSessionTool;
use App\Ai\Tools\Opencode\SearchSessionsTool;
use App\Models\BotSetting;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeSetting;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Opencode\OpencodeSessionParser;
use App\Services\Opencode\OpencodeSessionStore;
use App\Services\Opencode\OpencodeSessionWatcher;
use Laravel\Ai\Tools\Request;
use Tests\Feature\Opencode\Support\FakeOpencodeSessionManager;
use Tests\Support\OpencodeStoreFixture;

use function Pest\Laravel\mock;

/**
 * Feature-level fake shared by the watcher and the tools: records every
 * orchestration call for the tools and answers the read-only watcher calls
 * (conversation via the Feature fake, no pending permissions).
 */
final class ExternalLifecycleOpencodeManager extends FakeOpencodeSessionManager
{
    public function pendingPermissions(): array
    {
        return [];
    }
}

/**
 * Create the owner user, point the bot settings at them and return their chat id.
 */
function externalLifecycleOwnerChat(): int
{
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $chatId = 987654321;
    TelegramChatConversation::factory()->create(['user_id' => $user->id, 'chat_id' => $chatId]);

    TelegramSetting::singleton()->update(['allowed_user_ids' => [$chatId]]);

    return $chatId;
}

/**
 * A live running tool part after the last step-finish.
 */
function externalLifecycleToolPart(string $id, string $sessionId, int $time, string $tool = 'bash'): array
{
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $time,
        'time_updated' => $time,
        'data' => json_encode(['type' => 'tool', 'tool' => $tool, 'state' => ['status' => 'running']]),
    ];
}

/**
 * A step-finish part closing a turn, which makes any earlier running part stale.
 */
function externalLifecycleStepFinishPart(string $id, string $sessionId, int $time): array
{
    return [
        'id' => $id,
        'session_id' => $sessionId,
        'time_created' => $time,
        'time_updated' => $time,
        'data' => json_encode(['type' => 'step-finish', 'reason' => 'stop']),
    ];
}

/**
 * Append a part to the temporary opencode database and bump the session's
 * time_updated so the new activity is always discoverable.
 */
function externalLifecycleAppendPart(string $path, array $part): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $statement = $pdo->prepare('INSERT INTO part (id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$part['id'], $part['session_id'], $part['time_created'], $part['time_updated'], $part['data']]);

    $update = $pdo->prepare('UPDATE session SET time_updated = ? WHERE id = ?');
    $update->execute([$part['time_updated'], $part['session_id']]);
}

/**
 * A conversation transcript whose last assistant message is the given text.
 */
function externalLifecycleTranscript(string $assistant): string
{
    return "--- Message 1 [user] ---\nGo.\n\n--- Message 2 [assistant] ---\n{$assistant}";
}

/**
 * Build the watcher over the real fixture store and the recording manager,
 * collecting every notification into $messages (passed by reference).
 */
function externalLifecycleWatcher(
    OpencodeSessionStore $store,
    ExternalLifecycleOpencodeManager $manager,
    ?array &$messages = null,
): OpencodeSessionWatcher {
    $messages ??= [];
    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldReceive('notify')->andReturnUsing(
        function (int $chatId, string $markdown) use (&$messages): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown];

            return true;
        },
    );

    return new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);
}

beforeEach(function () {
    // Transition ticks simulate steady state after boot unless a test re-arms it.
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(2)->startOfSecond(),
        'session_watch_boot_reported_at' => now(),
    ]);
});

afterEach(function () {
    OpencodeStoreFixture::cleanup();
});

test('walks the full external session lifecycle: boot report, question turns, finish, bot work, dismissal and reactivation', function () {
    $chatId = externalLifecycleOwnerChat();
    $sessionId = 'ses_ext_001';
    $directory = '/home/junior/Projects/DevWarden';
    $title = 'Refactor the auth module';

    $manager = new ExternalLifecycleOpencodeManager;

    // ---------------------------------------------------------------------
    // Phase 1 - Boot: one consolidated "active since startup" report per boot.
    // ---------------------------------------------------------------------
    $base = now()->addSeconds(30)->getTimestampMs();

    $path = OpencodeStoreFixture::create(
        sessions: [
            ['id' => $sessionId, 'title' => $title, 'directory' => $directory, 'time_created' => $base - 5000, 'time_updated' => $base, 'time_archived' => null],
        ],
        parts: [
            externalLifecycleStepFinishPart('part_boot_finish', $sessionId, $base - 2000),
            externalLifecycleToolPart('part_boot_running', $sessionId, $base, 'bash'),
        ],
    );

    $store = new OpencodeSessionStore($path);

    // Simulate a server restart: stale watermark and unset boot marker.
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $watcher = externalLifecycleWatcher($store, $manager, $messages);

    $watcher->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:')
        ->and($messages[0]['text'])->toContain("- {$title} ({$directory}) — trabajando");

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();

    $watch = OpencodeSessionWatch::where('session_id', $sessionId)->first();

    expect($watch)->not->toBeNull()
        ->and($watch->last_seen_status)->toBe('unknown');

    // A second steady-state tick never re-emits the boot summary.
    $watcher->check();

    expect($messages)->toHaveCount(1);

    // ---------------------------------------------------------------------
    // Phase 2 - Question turn: one notification per live 'question' turn.
    // ---------------------------------------------------------------------
    externalLifecycleAppendPart($path, externalLifecycleToolPart('part_q1', $sessionId, now()->addSeconds(40)->getTimestampMs(), 'question'));

    $manager->conversation = externalLifecycleTranscript('¿Aplico los cambios?');

    $watcher->check();

    expect($messages)->toHaveCount(2)
        ->and($messages[1]['text'])->toContain('La sesión de opencode "Refactor the auth module" tiene preguntas.')
        ->and($messages[1]['text'])->toContain('¿Aplico los cambios?')
        ->and($messages[1]['text'])->toContain('Responde para continuar.');

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBe('question');

    // The same turn waiting in the next tick is not notified again.
    $watcher->check();

    expect($messages)->toHaveCount(2);

    // The session works on another tool, which clears the turn marker.
    externalLifecycleAppendPart($path, externalLifecycleToolPart('part_work', $sessionId, now()->addSeconds(50)->getTimestampMs(), 'bash'));

    $manager->conversation = externalLifecycleTranscript('Working on it...');

    $watcher->check();

    expect($messages)->toHaveCount(2);

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull();

    // A brand new question turn is a new milestone and is notified again.
    externalLifecycleAppendPart($path, externalLifecycleToolPart('part_q2', $sessionId, now()->addSeconds(60)->getTimestampMs(), 'question'));

    $manager->conversation = externalLifecycleTranscript('¿Confirmas la ruta del proyecto?');

    $watcher->check();

    expect($messages)->toHaveCount(3)
        ->and($messages[2]['text'])->toContain('tiene preguntas.')
        ->and($messages[2]['text'])->toContain('¿Confirmas la ruta del proyecto?');

    // ---------------------------------------------------------------------
    // Phase 3 - Reliable finish: working -> stopped notifies 'finished'.
    // ---------------------------------------------------------------------
    externalLifecycleAppendPart($path, externalLifecycleStepFinishPart('part_finish', $sessionId, now()->addSeconds(70)->getTimestampMs()));

    $manager->conversation = externalLifecycleTranscript('The auth module was refactored.');

    $watcher->check();

    expect($messages)->toHaveCount(4)
        ->and($messages[3]['text'])->toContain('La sesión de opencode "Refactor the auth module" terminó.')
        ->and($messages[3]['text'])->toContain("Proyecto: {$directory}")
        ->and($messages[3]['text'])->toContain('The auth module was refactored.');

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->project_path)->toBe($directory);

    // ---------------------------------------------------------------------
    // Phase 4 - The bot works in the session via OpencodeAskTool.
    // ---------------------------------------------------------------------
    $ask = new OpencodeAskTool($manager, $store);

    $background = $ask->handle(new Request([
        'session_id' => $sessionId,
        'question' => 'Execute step 3 now',
    ]));

    expect($background)->toContain('Prompt sent to session')->toContain($sessionId);

    $backgroundCall = $manager->lastCall('advanceSession');

    expect($backgroundCall['sessionId'])->toBe($sessionId)
        ->and($backgroundCall['directory'])->toBe($directory)
        ->and($backgroundCall['prompt'])->toBe('Execute step 3 now');

    $manager->replyMessage = 'Here is the answer.';

    $blocking = $ask->handle(new Request([
        'session_id' => $sessionId,
        'question' => 'What did you find?',
        'blocking' => true,
    ]));

    expect($blocking)->toBe('Here is the answer.');

    $replyCall = $manager->lastCall('reply');

    expect($replyCall['sessionId'])->toBe($sessionId)
        ->and($replyCall['directory'])->toBe($directory)
        ->and($replyCall['prompt'])->toBe('What did you find?');

    // ---------------------------------------------------------------------
    // Phase 5 - Marking the session done silences the watcher.
    // ---------------------------------------------------------------------
    $markDone = new MarkSessionDoneTool($manager);
    $markResult = $markDone->handle(new Request(['session_id' => $sessionId]));

    expect($markResult)->toContain($sessionId)->toContain('marked as done')
        ->and(OpencodeSessionDismissal::whereKey($sessionId)->exists())->toBeTrue();

    // Even when the session looks active again, transitions are not reported.
    externalLifecycleAppendPart($path, externalLifecycleToolPart('part_rework', $sessionId, now()->addSeconds(80)->getTimestampMs(), 'bash'));

    $before = count($messages);

    $watcher->check();

    expect($messages)->toHaveCount($before);

    // A fresh restart also leaves the dismissed session out of the boot summary.
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $watcher->check();

    expect($messages)->toHaveCount($before);

    // ---------------------------------------------------------------------
    // Phase 6 - Remember + reactivate: search, undo the dismissal, ask again.
    // ---------------------------------------------------------------------
    $search = new SearchSessionsTool($manager, $store);
    $searchResult = $search->handle(new Request(['query' => 'auth module']));

    expect($searchResult)->toContain('Found 1 opencode session')
        ->toContain($sessionId)
        ->toContain($title)
        ->toContain('dismissed');

    $reactivate = new ReactivateSessionTool($manager);
    $reactivateResult = $reactivate->handle(new Request(['session_id' => $sessionId]));

    expect($reactivateResult)->toContain($sessionId)->toContain('reactivated')
        ->and(OpencodeSessionDismissal::whereKey($sessionId)->exists())->toBeFalse();

    $again = $ask->handle(new Request([
        'session_id' => $sessionId,
        'question' => 'Keep going',
    ]));

    expect($again)->toContain('Prompt sent to session')->toContain($sessionId);

    $againCall = $manager->lastCall('advanceSession');

    expect($againCall['sessionId'])->toBe($sessionId)
        ->and($againCall['directory'])->toBe($directory)
        ->and($againCall['prompt'])->toBe('Keep going');
});
