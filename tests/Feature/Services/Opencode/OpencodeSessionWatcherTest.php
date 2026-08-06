<?php

use App\Models\BotSetting;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeSetting;
use App\Models\OpencodeWorkflow;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionParser;
use App\Services\Opencode\OpencodeSessionStore;
use App\Services\Opencode\OpencodeSessionWatcher;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\mock;

/**
 * Create the owner user, point the bot settings at them and return their chat id.
 */
function sessionWatcherOwnerChat(): int
{
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $chatId = 987654321;
    TelegramChatConversation::factory()->create(['user_id' => $user->id, 'chat_id' => $chatId]);

    TelegramSetting::singleton()->update(['allowed_user_ids' => [$chatId]]);

    return $chatId;
}

/**
 * Epoch milliseconds for a recently active session.
 */
function sessionWatcherFresh(): int
{
    return now()->getTimestampMs();
}

/**
 * Epoch milliseconds for a session last updated more than a day ago.
 */
function sessionWatcherOld(): int
{
    return now()->subDays(2)->getTimestampMs();
}

/**
 * A default session state (idle, recently active, with parts, in the allowed
 * project), merged with the given overrides.
 *
 * @return array{title: ?string, directory: ?string, time_updated: ?int, has_running_part: bool, has_error_part: bool, has_any_part: bool, last_turn_tool: ?string}
 */
function sessionWatcherState(array $overrides = []): array
{
    return array_merge([
        'title' => null,
        'directory' => '/home/junior/Projects/DevWarden',
        'time_updated' => sessionWatcherFresh(),
        'has_running_part' => false,
        'has_error_part' => false,
        'has_any_part' => true,
        'last_turn_tool' => null,
    ], $overrides);
}

/**
 * Build the watcher service with a mocked store, manager and notifier. Session
 * states, terminal-error signals, conversations, pending permissions and the
 * project whitelist are configurable per session; every notification is
 * appended to $messages (passed by reference).
 *
 * @param  array<int, array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>  $sessions
 * @param  array<string, array{title: ?string, directory: ?string, time_updated: ?int, has_running_part: bool, has_error_part: bool, has_any_part: bool, last_turn_tool: ?string}>  $states
 * @param  array<string, bool>  $terminalErrors
 * @param  array<int, array{id?: string, sessionId?: string, permissionId?: string, text: string}>  $permissions
 * @param  array<string, string>  $conversations
 * @param  array<int, array{chat_id: int, text: string, keyboard?: ?array}>  $messages
 * @param  list<string>  $storeThrows
 * @param  array<string, list<array{question: string, options: list<array{label: string, description: ?string}>}>>  $questionOptions
 */
function sessionWatcherService(
    array $sessions = [],
    array $states = [],
    array $terminalErrors = [],
    array $permissions = [],
    array $conversations = [],
    bool $allowsAllProjects = true,
    ?callable $onNotify = null,
    ?array &$messages = null,
    array $storeThrows = [],
    array $questionOptions = [],
): OpencodeSessionWatcher {
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn($sessions);
    $store->shouldReceive('sessionState')->andReturnUsing(
        function (string $id) use ($states, $storeThrows): array {
            if (in_array('sessionState', $storeThrows, true)) {
                throw new OpencodeException('opencode database read failed');
            }

            return $states[$id] ?? sessionWatcherState();
        },
    );
    $store->shouldReceive('hasTerminalError')->andReturnUsing(
        function (string $id) use ($terminalErrors, $storeThrows): bool {
            if (in_array('hasTerminalError', $storeThrows, true)) {
                throw new OpencodeException('opencode database read failed');
            }

            return $terminalErrors[$id] ?? false;
        },
    );
    $store->shouldReceive('questionOptions')->andReturnUsing(
        function (string $id) use ($questionOptions, $storeThrows): array {
            if (in_array('questionOptions', $storeThrows, true)) {
                throw new OpencodeException('opencode database read failed');
            }

            return $questionOptions[$id] ?? [];
        },
    );

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('conversation')->andReturnUsing(
        fn (string $id, string $directory): string => $conversations[$id] ?? '',
    );
    $manager->shouldReceive('pendingPermissions')->andReturn($permissions);
    $manager->shouldReceive('isAllowedProject')->andReturn($allowsAllProjects);

    $messages ??= [];
    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldReceive('notify')->andReturnUsing(
        function (int $chatId, string $markdown, ?array $keyboard = null) use (&$messages, $onNotify): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown, 'keyboard' => $keyboard];

            return $onNotify === null ? true : $onNotify($chatId, $markdown);
        },
    );

    return new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);
}

function sessionWatcherTranscript(string $assistant): string
{
    return "--- Message 1 [user] ---\nGo.\n\n--- Message 2 [assistant] ---\n{$assistant}";
}

beforeEach(function () {
    // Transition tests simulate steady-state ticks after boot: the watch
    // watermark is recent and the boot summary has already been emitted.
    // Boot-summary tests override this to simulate a fresh restart.
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(2)->startOfSecond(),
        'session_watch_boot_reported_at' => now(),
    ]);
});

test('does nothing when no owner is configured', function () {
    $service = sessionWatcherService([
        ['id' => 'ses_ext1', 'title' => 'External task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
    ]);

    $service->check();

    expect(OpencodeSessionWatch::count())->toBe(0);
});

test('does nothing when the owner has no telegram conversation', function () {
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $service = sessionWatcherService([
        ['id' => 'ses_ext1', 'title' => 'External task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
    ]);

    $service->check();

    expect(OpencodeSessionWatch::count())->toBe(0);
});

test('registers a newly discovered session without notifying', function () {
    $chatId = sessionWatcherOwnerChat();

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_new', 'title' => 'Fresh session', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        messages: $messages,
    );

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_new')->first();

    expect($watch)->not->toBeNull()
        ->and($watch->chat_id)->toBe($chatId)
        ->and($watch->title)->toBe('Fresh session')
        ->and($watch->last_seen_status)->toBe('unknown')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($messages)->toBeEmpty();
});

test('stays working and does not notify while a part is running', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'title' => 'Build feature',
        'chat_id' => $chatId,
        'last_seen_status' => 'unknown',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_work', 'title' => 'Build feature', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_work' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_work')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->checked_at)->not->toBeNull();
});

test('notifies finished when a session transitions from working to idle', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'title' => 'Build feature',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_work', 'title' => 'Build feature', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_work' => sessionWatcherTranscript('The feature is done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Build feature" terminó.')
        ->and($messages[0]['text'])->toContain('Proyecto: /home/junior/Projects/DevWarden')
        ->and($messages[0]['text'])->toContain('The feature is done.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_work')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->project_path)->toBe('/home/junior/Projects/DevWarden')
        ->and($watch->notified_at)->not->toBeNull();
});

test('notifies question when the last assistant message ends with a question', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_q',
        'title' => 'Ask me',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_q', 'title' => 'Ask me', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_q' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Ask me" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('Responde para continuar.');

    expect(OpencodeSessionWatch::where('session_id', 'ses_q')->first()->last_notified_event)->toBe('question');
});

test('notifies question when the session has a pending permission', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_perm',
        'title' => 'Needs permission',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_perm', 'title' => 'Needs permission', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_perm' => sessionWatcherTranscript('Waiting on you.')],
        permissions: [
            ['sessionId' => 'ses_perm', 'permissionId' => 'perm_1', 'text' => 'run command `ls`'],
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('tiene preguntas.');

    expect(OpencodeSessionWatch::where('session_id', 'ses_perm')->first()->last_notified_event)->toBe('question');
});

test('notifies error when the session ends on a terminal tool error', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_err',
        'title' => 'Failing task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_err', 'title' => 'Failing task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_err' => sessionWatcherState(['has_error_part' => true])],
        terminalErrors: ['ses_err' => true],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Failing task" falló.')
        ->and($messages[0]['text'])->toContain('Proyecto: /home/junior/Projects/DevWarden');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_err')->first();

    expect($watch->last_seen_status)->toBe('error')
        ->and($watch->last_notified_event)->toBe('error');
});

test('does not notify an error when the error is not terminal even with an error part', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_transient',
        'title' => 'Transient error',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => 'finished',
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_transient', 'title' => 'Transient error', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_transient' => sessionWatcherState(['has_error_part' => true])],
        terminalErrors: ['ses_transient' => false],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_transient')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished');
});

test('does not re-notify an error within the 24h cooldown', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_cooldown',
        'title' => 'Already reported',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
        'last_notified_event' => 'error',
        'notified_at' => now()->subHour(),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_cooldown', 'title' => 'Already reported', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_cooldown' => sessionWatcherState(['has_error_part' => true])],
        terminalErrors: ['ses_cooldown' => true],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_cooldown')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->notified_at)->not->toBeNull()
        ->and($watch->checked_at)->not->toBeNull();
});

test('never notifies a session without any part', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_empty',
        'title' => 'No parts yet',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_empty', 'title' => 'No parts yet', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_empty' => sessionWatcherState(['has_any_part' => false])],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_empty')->first();

    expect($watch->last_notified_event)->toBeNull();
});

test('does not notify a fresh idle session with parts that never transitioned from working', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_no_todos',
        'title' => 'Idle without tasks',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => null,
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_no_todos', 'title' => 'Idle without tasks', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_no_todos' => sessionWatcherTranscript('Nothing left to do.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_no_todos')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull();
});

test('does not notify when the session was already stopped and notified', function () {
    $chatId = sessionWatcherOwnerChat();
    $watch = OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_stable',
        'title' => 'Stable',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => 'finished',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_stable', 'title' => 'Stable', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch->refresh();
    expect($watch->last_notified_event)->toBe('finished');
});

test('skips the heavy inspection for a recently checked stopped session', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_skipped',
        'title' => 'Already stopped',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => 'finished',
    ]);

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_skipped', 'title' => 'Already stopped', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
    ]);
    $store->shouldNotReceive('sessionState');
    $store->shouldNotReceive('hasTerminalError');

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldNotReceive('conversation');
    $manager->shouldNotReceive('isAllowedProject');

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();
});

test('re-activates a stopped notified session that goes back to working and stops again', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_again',
        'title' => 'Round two',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => 'finished',
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_again', 'title' => 'Round two', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_again' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();
    expect(OpencodeSessionWatch::where('session_id', 'ses_again')->first()->last_seen_status)->toBe('working');

    $service = sessionWatcherService(
        sessions: [['id' => 'ses_again', 'title' => 'Round two', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_again' => sessionWatcherTranscript('Part two done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Round two" terminó.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_again')->first();
    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished');
});

test('does not notify a fresh already-stopped session that never transitioned', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_fresh',
        'title' => 'Fresh done',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => null,
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_fresh', 'title' => 'Fresh done', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_fresh' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_fresh')->first();
    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull();
});

test('does not notify an old stopped session never notified before (anti-spam for history)', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_old',
        'title' => 'Old done',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => null,
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_old', 'title' => 'Old done', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherOld()]],
        states: ['ses_old' => sessionWatcherState(['time_updated' => sessionWatcherOld()])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_old')->first();
    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull();
});

test('skips sessions managed by an opencode workflow', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeWorkflow::factory()->create([
        'chat_id' => $chatId,
        'opencode_session_id' => 'ses_workflow',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_workflow', 'title' => 'Workflow task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty()
        ->and(OpencodeSessionWatch::where('session_id', 'ses_workflow')->exists())->toBeFalse();
});

test('skips a dismissed session without inspecting or notifying', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_dismissed']);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_dismissed',
        'title' => 'Marked done',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturn([
        ['id' => 'ses_dismissed', 'title' => 'Marked done', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
    ]);
    $store->shouldNotReceive('sessionState');
    $store->shouldNotReceive('hasTerminalError');

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldNotReceive('conversation');
    $manager->shouldNotReceive('isAllowedProject');

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_dismissed')->first();

    expect($watch)->not->toBeNull()
        ->and($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('skips a session whose directory is outside the allowed projects without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_outside',
        'title' => 'Rogue',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_outside', 'title' => 'Rogue', 'directory' => '/etc/outside', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_outside' => sessionWatcherState(['directory' => '/etc/outside'])],
        allowsAllProjects: false,
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_outside')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull();
});

test('keeps the previous status when sessionState throws without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_transport',
        'title' => 'State fail',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $service = sessionWatcherService(
        sessions: [['id' => 'ses_transport', 'title' => 'State fail', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        storeThrows: ['sessionState'],
    );

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_transport')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('keeps the previous status when hasTerminalError throws without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_terminal_fail',
        'title' => 'Terminal signal fail',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $service = sessionWatcherService(
        sessions: [['id' => 'ses_terminal_fail', 'title' => 'Terminal signal fail', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_terminal_fail' => sessionWatcherState(['has_error_part' => true])],
        storeThrows: ['hasTerminalError'],
    );

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_terminal_fail')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('keeps the previous status when the notification fails so it is retried next tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_retry',
        'title' => 'Retry me',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
        'last_notified_event' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_retry', 'title' => 'Retry me', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_retry' => sessionWatcherTranscript('Done.')],
        onNotify: fn () => false,
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_retry')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('retries a failed notification on the next tick and marks the session stopped once it succeeds', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_retry_ok',
        'title' => 'Retry then win',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $shouldFail = true;
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_retry_ok', 'title' => 'Retry then win', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_retry_ok' => sessionWatcherTranscript('Done.')],
        onNotify: function () use (&$shouldFail): bool {
            return ! $shouldFail;
        },
        messages: $messages,
    );

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_retry_ok')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull()
        ->and($messages)->toHaveCount(1);

    $shouldFail = false;
    $service->check();

    $watch->refresh();

    expect($messages)->toHaveCount(2)
        ->and($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('does not re-notify a question on a later tick with the same stopped session', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_q_once',
        'title' => 'Ask once',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_q_once', 'title' => 'Ask once', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_q_once' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and(OpencodeSessionWatch::where('session_id', 'ses_q_once')->first()->last_notified_event)->toBe('question');

    $service->check();

    expect($messages)->toHaveCount(1);
});

test('does not re-notify a stopped session re-checked after the stable window', function () {
    $chatId = sessionWatcherOwnerChat();
    $watch = OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_stale_check',
        'title' => 'Checked long ago',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'last_notified_event' => 'finished',
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_stale_check', 'title' => 'Checked long ago', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch->refresh();
    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished');
});

test('prefers a chat inside allowed_user_ids over a newer stale conversation', function () {
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $realChatId = 5068985554;
    $staleChatId = 123456789;

    TelegramChatConversation::factory()->create(['user_id' => $user->id, 'chat_id' => $realChatId]);
    TelegramChatConversation::factory()->create(['user_id' => $user->id, 'chat_id' => $staleChatId]);
    TelegramSetting::singleton()->update(['allowed_user_ids' => [$realChatId]]);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_chatpick',
        'title' => 'Chat pick',
        'chat_id' => $staleChatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_chatpick', 'title' => 'Chat pick', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_chatpick' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($realChatId);
});

test('falls back to the latest owner conversation when allowed_user_ids is empty', function () {
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $chatId = 123456789;
    TelegramChatConversation::factory()->create(['user_id' => $user->id, 'chat_id' => $chatId]);

    TelegramSetting::singleton()->update(['allowed_user_ids' => []]);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_fallback',
        'title' => 'Fallback',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_fallback', 'title' => 'Fallback', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_fallback' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId);
});

test('registers a newly discovered sub-agent session with is_subagent without notifying', function () {
    $chatId = sessionWatcherOwnerChat();

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_sub', 'title' => 'Sub-agent task', 'directory' => '/home/junior/Projects/DevWarden', 'parent_id' => 'ses_tui', 'time_updated' => sessionWatcherFresh()]],
        messages: $messages,
    );

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_sub')->first();

    expect($watch)->not->toBeNull()
        ->and($watch->is_subagent)->toBeTrue()
        ->and($watch->last_seen_status)->toBe('unknown')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull()
        ->and($messages)->toBeEmpty();
});

test('tracks a running sub-agent session as working without notifying', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_sub',
        'title' => 'Sub-agent task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_sub', 'title' => 'Sub-agent task', 'directory' => '/home/junior/Projects/DevWarden', 'parent_id' => 'ses_tui', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_sub' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_sub')->first();

    expect($watch->is_subagent)->toBeTrue()
        ->and($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('does not notify a sub-agent session on a working to stopped transition', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_sub',
        'title' => 'Sub-agent task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_sub', 'title' => 'Sub-agent task', 'directory' => '/home/junior/Projects/DevWarden', 'parent_id' => 'ses_tui', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_sub' => sessionWatcherTranscript('Sub done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_sub')->first();

    expect($watch->is_subagent)->toBeTrue()
        ->and($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('does not notify a sub-agent session on a terminal error', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_sub',
        'title' => 'Failing sub-agent',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_sub', 'title' => 'Failing sub-agent', 'directory' => '/home/junior/Projects/DevWarden', 'parent_id' => 'ses_tui', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_sub' => sessionWatcherState(['has_error_part' => true])],
        terminalErrors: ['ses_sub' => true],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_sub')->first();

    expect($watch->is_subagent)->toBeTrue()
        ->and($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

/**
 * Create a temporary opencode-like SQLite database seeded with the given
 * session rows, and register it for cleanup.
 */
function opencodeWatcherFixture(array $sessions = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'opencode_watch_');

    $GLOBALS['opencode_watch_fixtures'][] = $path;

    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
        CREATE TABLE session (
            id TEXT PRIMARY KEY,
            title TEXT NOT NULL,
            directory TEXT NOT NULL,
            parent_id TEXT,
            time_created INTEGER NOT NULL,
            time_updated INTEGER NOT NULL,
            time_archived INTEGER
        );
        SQL);

    $statement = $pdo->prepare('INSERT INTO session (id, title, directory, parent_id, time_created, time_updated, time_archived) VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach ($sessions as $session) {
        $statement->execute([
            $session['id'],
            $session['title'],
            $session['directory'],
            $session['parent_id'] ?? null,
            $session['time_created'] ?? $session['time_updated'],
            $session['time_updated'],
            $session['time_archived'] ?? null,
        ]);
    }

    return $path;
}

afterEach(function () {
    foreach ($GLOBALS['opencode_watch_fixtures'] ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    $GLOBALS['opencode_watch_fixtures'] = [];
});

test('initializes the session watch watermark on the first check and uses it as the discovery cutoff', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update(['session_watch_since' => null]);

    $received = [];
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use (&$received): array {
            $received[] = $sinceEpochMs;

            return [];
        },
    );

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $notifier = mock(OpencodeNotifier::class);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watermark = OpencodeSetting::singleton()->session_watch_since;

    // The first call is the discovery cutoff at the fresh watermark; the
    // second is the boot summary re-run with a grace window before it.
    expect($watermark)->not->toBeNull()
        ->and($received)->toHaveCount(2)
        ->and($received[0])->toBeInt()
        ->and(intdiv($received[0], 1000))->toBe($watermark->getTimestamp())
        ->and(intdiv($received[1], 1000))->toBe($watermark->getTimestamp() - 5 * 60);
});

test('keeps a recent session watch watermark without resetting it', function () {
    sessionWatcherOwnerChat();

    $recent = now()->subMinutes(2)->startOfSecond();
    OpencodeSetting::singleton()->update(['session_watch_since' => $recent]);

    $received = null;
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use (&$received): array {
            $received = $sinceEpochMs;

            return [];
        },
    );

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $notifier = mock(OpencodeNotifier::class);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watermark = OpencodeSetting::singleton()->session_watch_since;

    expect($watermark->getTimestampMs())->toBe($recent->getTimestampMs())
        ->and($received)->toBe($recent->getTimestampMs());
});

test('resets the session watch watermark when the watcher has not ticked for over ten minutes', function () {
    sessionWatcherOwnerChat();

    $stale = now()->subMinutes(15);
    OpencodeSetting::singleton()->update(['session_watch_since' => $stale]);

    $received = [];
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use (&$received): array {
            $received[] = $sinceEpochMs;

            return [];
        },
    );

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $notifier = mock(OpencodeNotifier::class);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watermark = OpencodeSetting::singleton()->session_watch_since;

    // Discovery uses the fresh watermark; the boot summary re-run uses a grace
    // window five minutes before it.
    expect($watermark)->not->toBeNull()
        ->and($received)->toHaveCount(2)
        ->and(intdiv($received[0], 1000))->toBe($watermark->getTimestamp())
        ->and(intdiv($received[1], 1000))->toBe($watermark->getTimestamp() - 5 * 60)
        ->and($watermark->isAfter($stale))->toBeTrue();
});

test('does not discover a session updated before the session watch watermark', function () {
    sessionWatcherOwnerChat();

    $watermark = now();
    OpencodeSetting::singleton()->update(['session_watch_since' => $watermark]);

    $path = opencodeWatcherFixture([
        ['id' => 'ses_old', 'title' => 'Old', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => $watermark->subMinutes(30)->getTimestampMs()],
    ]);

    $store = new OpencodeSessionStore($path);
    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldNotReceive('conversation');
    $manager->shouldNotReceive('isAllowedProject');

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    expect(OpencodeSessionWatch::where('session_id', 'ses_old')->exists())->toBeFalse();
});

test('falls back to discovering all sessions when the watermark cannot be resolved', function () {
    sessionWatcherOwnerChat();

    Schema::drop('opencode_settings');

    $received = null;
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use (&$received): array {
            $received = $sinceEpochMs;

            return [];
        },
    );

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $notifier = mock(OpencodeNotifier::class);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    expect($received)->toBeNull();
});

test('sends one boot summary for sessions active since a restart and never repeats it', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_active', 'title' => 'Feature work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_active' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:')
        ->and($messages[0]['text'])->toContain('- Feature work (/home/junior/Projects/DevWarden) — trabajando');

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('boot summary marks a live question turn as waiting for the answer', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_q', 'title' => 'Ask me', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_q' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('- Ask me (/home/junior/Projects/DevWarden) — esperando respuesta');
});

test('does not emit a boot summary when no session is active since the restart', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_idle',
        'title' => 'Idle',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopped',
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_idle', 'title' => 'Idle', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toBeEmpty();
});

test('boot summary skips workflow, dismissed and sub-agent sessions', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    OpencodeWorkflow::factory()->create([
        'chat_id' => $chatId,
        'opencode_session_id' => 'ses_workflow',
    ]);
    OpencodeSessionDismissal::factory()->create(['session_id' => 'ses_dismissed']);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_workflow', 'title' => 'Workflow task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
            ['id' => 'ses_dismissed', 'title' => 'Marked done', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
            ['id' => 'ses_sub', 'title' => 'Sub-agent task', 'directory' => '/home/junior/Projects/DevWarden', 'parent_id' => 'ses_tui', 'time_updated' => sessionWatcherFresh()],
            ['id' => 'ses_real', 'title' => 'Real work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: [
            'ses_workflow' => sessionWatcherState(['has_running_part' => true]),
            'ses_dismissed' => sessionWatcherState(['has_running_part' => true]),
            'ses_sub' => sessionWatcherState(['has_running_part' => true]),
            'ses_real' => sessionWatcherState(['has_running_part' => true]),
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('- Real work')
        ->and($messages[0]['text'])->not->toContain('Workflow task')
        ->and($messages[0]['text'])->not->toContain('Marked done')
        ->and($messages[0]['text'])->not->toContain('Sub-agent task');
});

test('retries the boot summary on the next tick when the notification fails', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $shouldFail = true;
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_active', 'title' => 'Feature work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_active' => sessionWatcherState(['has_running_part' => true])],
        onNotify: function () use (&$shouldFail): bool {
            return ! $shouldFail;
        },
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->toBeNull();

    $shouldFail = false;
    $service->check();

    expect($messages)->toHaveCount(2)
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('boot summary includes a session updated shortly before the watermark reset (grace window)', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $session = ['id' => 'ses_grace', 'title' => 'Just before boot', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()];

    // Discovery at the exact watermark sees nothing (the boot race); the boot
    // summary re-run with the grace window sees the session updated a few
    // seconds before the restart.
    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use ($session): array {
            if ($sinceEpochMs !== null && $sinceEpochMs < now()->subMinute()->getTimestampMs()) {
                return [$session];
            }

            return [];
        },
    );
    $store->shouldReceive('sessionState')->andReturn(sessionWatcherState(['has_running_part' => true]));

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldReceive('isAllowedProject')->andReturn(true);

    $messages = [];
    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldReceive('notify')->andReturnUsing(
        function (int $chatId, string $markdown) use (&$messages): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown];

            return true;
        },
    );

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:')
        ->and($messages[0]['text'])->toContain('- Just before boot (/home/junior/Projects/DevWarden) — trabajando');

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('does not seal the boot summary when discovery is empty and retries on the next tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(sessions: [], messages: $messages);

    $service->check();

    expect($messages)->toBeEmpty()
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->toBeNull();

    // A session appears on the next tick while the retry window is still open:
    // the boot summary is emitted instead of having been sealed on the first
    // empty tick.
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_late', 'title' => 'Late work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_late' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('- Late work (/home/junior/Projects/DevWarden) — trabajando');

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('seals the boot summary without a message once the retry window expires', function () {
    sessionWatcherOwnerChat();

    // Watermark is four minutes old (not yet a detected restart) and the boot
    // marker was never sealed: the three-minute retry window has expired, so
    // an empty boot seals without a message instead of retrying forever.
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(4)->startOfSecond(),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(sessions: [], messages: $messages);

    $service->check();

    expect($messages)->toBeEmpty()
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('marks the boot summary as reported only after a successful send', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_sent', 'title' => 'Sent work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_sent' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('does not break the tick when the boot summary marker cannot be read', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_active',
        'title' => 'Feature work',
        'chat_id' => $chatId,
        'last_seen_status' => 'unknown',
    ]);

    Schema::drop('opencode_settings');

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_active', 'title' => 'Feature work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_active' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_active')->first();

    expect($watch->last_seen_status)->toBe('working');
});

test('notifies once per live question turn and again when a new turn starts', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_qturn',
        'title' => 'Turn taker',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_qturn', 'title' => 'Turn taker', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_qturn' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        conversations: ['ses_qturn' => sessionWatcherTranscript('¿Aplico los cambios?')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Turn taker" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('¿Aplico los cambios?')
        ->and($messages[0]['text'])->toContain('Responde para continuar.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_qturn')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();

    // The session works on something else, which clears the turn marker.
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_qturn', 'title' => 'Turn taker', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_qturn' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'bash'])],
        conversations: ['ses_qturn' => sessionWatcherTranscript('Working...')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1);

    // A new question turn is a new milestone and is notified again.
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_qturn', 'title' => 'Turn taker', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_qturn' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        conversations: ['ses_qturn' => sessionWatcherTranscript('¿Puedo continuar?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(2)
        ->and($messages[1]['text'])->toContain('La sesión de opencode "Turn taker" tiene preguntas.')
        ->and($messages[1]['text'])->toContain('¿Puedo continuar?');
});

test('notifies finished when an interactive session drops its live running part', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_zombie',
        'title' => 'Interactive task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_zombie', 'title' => 'Interactive task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        conversations: ['ses_zombie' => sessionWatcherTranscript('The interactive task finished.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Interactive task" terminó.')
        ->and($messages[0]['text'])->toContain('The interactive task finished.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_zombie')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('keeps the plain question notification when the session has no answer options', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_no_opts',
        'title' => 'No options',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_no_opts', 'title' => 'No options', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_no_opts' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        questionOptions: ['ses_no_opts' => []],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toBe("La sesión de opencode \"No options\" tiene preguntas.\n\n¿Confirmas la ruta del proyecto?\n\nResponde para continuar.")
        ->and($messages[0]['keyboard'])->toBeNull();
});

test('notifies finished with the question options and an inline keyboard', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_finished_opts',
        'title' => 'Ask with options',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_finished_opts', 'title' => 'Ask with options', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_finished_opts' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        questionOptions: [
            'ses_finished_opts' => [
                [
                    'question' => '¿Confirmas la ruta del proyecto?',
                    'options' => [
                        ['label' => 'Sí', 'description' => null],
                        ['label' => 'No', 'description' => null],
                    ],
                ],
            ],
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Ask with options" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('Sesión: ses_finished_opts')
        ->and($messages[0]['text'])->toContain('Pregunta 1: ¿Confirmas la ruta del proyecto?')
        ->and($messages[0]['text'])->toContain('(a) Sí')
        ->and($messages[0]['text'])->toContain('(b) No')
        ->and($messages[0]['text'])->toContain('Toca un botón para responder.')
        ->and($messages[0]['keyboard'])->toBe([
            'inline_keyboard' => [
                [
                    ['text' => 'Sí', 'callback_data' => 'oq:ses_finished_opts:0:0'],
                    ['text' => 'No', 'callback_data' => 'oq:ses_finished_opts:0:1'],
                ],
            ],
        ]);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_finished_opts')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();
});

test('notifies a live question turn with the options and an inline keyboard', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_turn_opts',
        'title' => 'Live question',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_turn_opts', 'title' => 'Live question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_turn_opts' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        conversations: ['ses_turn_opts' => sessionWatcherTranscript('¿Aplico los cambios?')],
        questionOptions: [
            'ses_turn_opts' => [
                [
                    'question' => '¿Aplico los cambios?',
                    'options' => [
                        ['label' => 'Aplicar', 'description' => null],
                        ['label' => 'Cancelar', 'description' => null],
                    ],
                ],
            ],
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('Sesión: ses_turn_opts')
        ->and($messages[0]['text'])->toContain('Pregunta 1: ¿Aplico los cambios?')
        ->and($messages[0]['text'])->toContain('(a) Aplicar')
        ->and($messages[0]['text'])->toContain('(b) Cancelar')
        ->and($messages[0]['keyboard'])->toBe([
            'inline_keyboard' => [
                [
                    ['text' => 'Aplicar', 'callback_data' => 'oq:ses_turn_opts:0:0'],
                    ['text' => 'Cancelar', 'callback_data' => 'oq:ses_turn_opts:0:1'],
                ],
            ],
        ]);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_turn_opts')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();
});

test('builds one keyboard row per question with base-zero callback indices', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_multi_opts',
        'title' => 'Multi question',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_multi_opts', 'title' => 'Multi question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_multi_opts' => sessionWatcherTranscript('¿Elige opciones?')],
        questionOptions: [
            'ses_multi_opts' => [
                [
                    'question' => '¿Primera pregunta?',
                    'options' => [
                        ['label' => 'Opción A1', 'description' => null],
                        ['label' => 'Opción A2', 'description' => null],
                    ],
                ],
                [
                    'question' => '¿Segunda pregunta?',
                    'options' => [
                        ['label' => 'Opción B1', 'description' => null],
                    ],
                ],
            ],
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('Pregunta 1: ¿Primera pregunta?')
        ->and($messages[0]['text'])->toContain('(a) Opción A1')
        ->and($messages[0]['text'])->toContain('(b) Opción A2')
        ->and($messages[0]['text'])->toContain('Pregunta 2: ¿Segunda pregunta?')
        ->and($messages[0]['text'])->toContain('(a) Opción B1')
        ->and($messages[0]['keyboard'])->toBe([
            'inline_keyboard' => [
                [
                    ['text' => '1a: Opción A1', 'callback_data' => 'oq:ses_multi_opts:0:0'],
                    ['text' => '1b: Opción A2', 'callback_data' => 'oq:ses_multi_opts:0:1'],
                ],
                [
                    ['text' => '2a: Opción B1', 'callback_data' => 'oq:ses_multi_opts:1:0'],
                ],
            ],
        ]);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_multi_opts')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('question');
});
