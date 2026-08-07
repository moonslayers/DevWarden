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
 * Epoch milliseconds for a session idle long enough for the watcher to
 * confirm its finish (past the 3-minute confirmation window).
 */
function sessionWatcherConfirming(): int
{
    return now()->subMinutes(4)->getTimestampMs();
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
 * @param  array<int, array{chat_id: int, text: string, keyboard: ?array}>  $messages
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
        function (int $chatId, string $markdown) use (&$messages, $onNotify): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown, 'keyboard' => null];

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

test('notifies a live question turn on the first tick a session is discovered', function () {
    $chatId = sessionWatcherOwnerChat();

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_first_turn', 'title' => 'Fresh question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_first_turn' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        conversations: ['ses_first_turn' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Fresh question" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('¿Confirmas la ruta del proyecto?');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_first_turn')->first();

    expect($watch)->not->toBeNull()
        ->and($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();

    // The same pending turn is never re-notified on a later tick.
    $service->check();

    expect($messages)->toHaveCount(1);
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
        sessions: [['id' => 'ses_work', 'title' => 'Build feature', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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

test('notifies finished with the previous real assistant text when the last assistant block is (no content)', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_no_content',
        'title' => 'Deploy task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_no_content', 'title' => 'Deploy task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: [
            'ses_no_content' => "--- Message 1 [user] ---\n"
                ."Go.\n\n"
                ."--- Message 2 [assistant] ---\n"
                ."El deploy se completó.\n\n"
                ."--- Message 3 [assistant] ---\n"
                .'(no content)',
        ],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Deploy task" terminó.')
        ->and($messages[0]['text'])->toContain('Proyecto: /home/junior/Projects/DevWarden')
        ->and($messages[0]['text'])->toContain('El deploy se completó.')
        ->and($messages[0]['text'])->not->toContain('(no content)');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_no_content')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('sends the finished message when the session ends with a rhetorical question', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_q',
        'title' => 'Ask me',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_q', 'title' => 'Ask me', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_q' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Ask me" terminó.')
        ->and($messages[0]['text'])->toContain('Proyecto: /home/junior/Projects/DevWarden')
        ->and($messages[0]['text'])->toContain('¿Confirmas la ruta del proyecto?')
        ->and($messages[0]['text'])->not->toContain('tiene preguntas.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_q')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('sends the finished message when a session ends on a rhetorical trailing question with no live question turn or pending permission', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_retro',
        'title' => 'Retro end',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_retro', 'title' => 'Retro end', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_retro' => sessionWatcherTranscript('¿Procedemos con algo concreto, o era solo la prueba?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Retro end" terminó.')
        ->and($messages[0]['text'])->toContain('¿Procedemos con algo concreto, o era solo la prueba?')
        ->and($messages[0]['text'])->not->toContain('tiene preguntas.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_retro')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();

    $service->check();

    expect($messages)->toHaveCount(1);
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
        sessions: [['id' => 'ses_perm', 'title' => 'Needs permission', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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
        sessions: [['id' => 'ses_again', 'title' => 'Round two', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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
        sessions: [['id' => 'ses_retry', 'title' => 'Retry me', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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
        sessions: [['id' => 'ses_retry_ok', 'title' => 'Retry then win', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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

test('does not re-notify a finished session on a later tick with the same stopped session', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_q_once',
        'title' => 'Ask once',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_q_once', 'title' => 'Ask once', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_q_once' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('terminó.')
        ->and(OpencodeSessionWatch::where('session_id', 'ses_q_once')->first()->last_notified_event)->toBe('finished');

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
        sessions: [['id' => 'ses_chatpick', 'title' => 'Chat pick', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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
        sessions: [['id' => 'ses_fallback', 'title' => 'Fallback', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
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
        CREATE TABLE part (
            id INTEGER PRIMARY KEY,
            session_id TEXT NOT NULL,
            time_created INTEGER NOT NULL,
            data TEXT NOT NULL
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
    $notifier->shouldReceive('notify')->andReturn(true);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watermark = OpencodeSetting::singleton()->session_watch_since;

    // Both the discovery and the boot summary re-run apply the same grace
    // window, so a session idle just before the reset stays discoverable.
    expect($watermark)->not->toBeNull()
        ->and($received)->toHaveCount(2)
        ->and($received[0])->toBeInt()
        ->and(intdiv($received[0], 1000))->toBe($watermark->getTimestamp() - 5 * 60)
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
        ->and($received)->toBe($recent->getTimestampMs() - 5 * 60 * 1000);
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
    $notifier->shouldReceive('notify')->andReturn(true);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watermark = OpencodeSetting::singleton()->session_watch_since;

    // Both the discovery and the boot summary re-run apply the same grace
    // window; the reset also re-arms the boot summary marker.
    expect($watermark)->not->toBeNull()
        ->and($received)->toHaveCount(2)
        ->and(intdiv($received[0], 1000))->toBe($watermark->getTimestamp() - 5 * 60)
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

test('discovers a session idle just before the watermark reset (discovery grace window)', function () {
    sessionWatcherOwnerChat();

    $watermark = now()->subMinutes(2)->startOfSecond();
    OpencodeSetting::singleton()->update(['session_watch_since' => $watermark]);

    $path = opencodeWatcherFixture([
        ['id' => 'ses_grace', 'title' => 'Grace', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => $watermark->subMinute()->getTimestampMs()],
    ]);

    $store = new OpencodeSessionStore($path);
    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);

    $notifier = mock(OpencodeNotifier::class);

    $service = new OpencodeSessionWatcher($store, $manager, $notifier, new OpencodeSessionParser);

    $service->check();

    expect(OpencodeSessionWatch::where('session_id', 'ses_grace')->exists())->toBeTrue();
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

test('boot summary does not list a session waiting for an answer; its question turn is sent instead', function () {
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
        conversations: ['ses_q' => sessionWatcherTranscript('¿Aplico los cambios?')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    // The waiting session is excluded from the summary (only working sessions
    // are listed); the empty-boot message fires because no working session was
    // found, and the question turn is notified separately with the question.
    expect($messages)->toHaveCount(2);

    $question = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'tiene preguntas.'));
    $emptyBoot = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'No hay sesiones de opencode activas'));

    expect($question)->not->toBeNull()
        ->and($question['text'])->toContain('¿Aplico los cambios?')
        ->and($question['text'])->not->toContain('Sesiones activas desde el inicio del servidor:');

    expect($emptyBoot)->not->toBeNull();
});

test('sends the question turn alongside the boot summary on the first tick after a restart', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_boot_q', 'title' => 'Boot question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
            ['id' => 'ses_work', 'title' => 'Working', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: [
            'ses_boot_q' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question']),
            'ses_work' => sessionWatcherState(['has_running_part' => true]),
        ],
        conversations: ['ses_boot_q' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    // The boot summary lists the working session only; the waiting session
    // gets its dedicated question-turn message with the actual question.
    expect($messages)->toHaveCount(2);

    $summary = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'Sesiones activas desde el inicio del servidor:'));
    $question = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'tiene preguntas.'));

    expect($summary)->not->toBeNull()
        ->and($summary['text'])->toContain('- Working (/home/junior/Projects/DevWarden) — trabajando')
        ->and($summary['text'])->not->toContain('Boot question');

    expect($question)->not->toBeNull()
        ->and($question['text'])->toContain('Boot question')
        ->and($question['text'])->toContain('¿Confirmas la ruta del proyecto?');
});

test('sends the empty-boot message when the only session since the restart is idle', function () {
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

    // No working session since the restart: the boot still reports with the
    // empty-boot message, exactly once.
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('Sistema DevWarden iniciado')
        ->and($messages[0]['text'])->toContain('No hay sesiones de opencode activas')
        ->and($messages[0]['text'])->not->toContain('Sesiones activas desde el inicio del servidor:');
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

test('sends an immediate empty-boot message on the first tick when discovery is empty', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(sessions: [], messages: $messages);

    $service->check();

    // An empty boot is reported right away: no waiting for a retry window and
    // no silent seal, so the owner always gets a message on startup.
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('Sistema DevWarden iniciado')
        ->and($messages[0]['text'])->toContain('No hay sesiones de opencode activas');

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('retries the empty-boot message on the next tick when the notification fails', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $shouldFail = true;
    $service = sessionWatcherService(
        sessions: [],
        onNotify: function () use (&$shouldFail): bool {
            return ! $shouldFail;
        },
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('No hay sesiones de opencode activas')
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->toBeNull();

    $shouldFail = false;
    $service->check();

    expect($messages)->toHaveCount(2)
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('never repeats the empty-boot message once the marker is sealed', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(sessions: [], messages: $messages);

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('No hay sesiones de opencode activas');

    expect(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
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

test('emits the boot summary on a fast restart when the schedule:work anchor is newer', function () {
    $chatId = sessionWatcherOwnerChat();
    $anchor = now()->subMinutes(1)->startOfSecond();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(3),
        'session_watch_boot_reported_at' => null,
        'schedule_booted_at' => $anchor,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_fast', 'title' => 'Fast work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_fast' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    // The three-minute-old watermark does NOT trip the ten-minute restart
    // heuristic, yet the newer schedule:work anchor still triggers the boot
    // summary on the very first tick.
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:')
        ->and($messages[0]['text'])->toContain('- Fast work (/home/junior/Projects/DevWarden) — trabajando');

    $settings = OpencodeSetting::singleton();

    expect($settings->session_watch_since->equalTo($anchor))->toBeTrue()
        ->and($settings->session_watch_boot_reported_at)->not->toBeNull();
});

test('boot summary uses the boot grace window around the schedule:work anchor cutoff', function () {
    sessionWatcherOwnerChat();
    $anchor = now()->subMinutes(1)->startOfSecond();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(3)->startOfSecond(),
        'session_watch_boot_reported_at' => null,
        'schedule_booted_at' => $anchor,
    ]);

    // The session's last update predates the anchor (time_updated two minutes
    // before the schedule:work start) but still falls inside the five-minute
    // grace window, so the anchored summary must include it instead of using a
    // stale cutoff that would miss it.
    $session = ['id' => 'ses_anchor_grace', 'title' => 'Just before boot', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->subMinutes(2)->getTimestampMs()];

    $store = mock(OpencodeSessionStore::class);
    $store->shouldReceive('activeSessions')->andReturnUsing(
        function (?int $sinceEpochMs) use ($session): array {
            if ($sinceEpochMs !== null && $sinceEpochMs < now()->subMinutes(5)->getTimestampMs()) {
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

test('still emits the boot summary through the age heuristic when no boot anchor exists', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(15),
        'session_watch_boot_reported_at' => null,
        'schedule_booted_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_legacy', 'title' => 'Legacy work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_legacy' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:')
        ->and($messages[0]['text'])->toContain('- Legacy work (/home/junior/Projects/DevWarden) — trabajando');
});

test('does not emit a boot summary without a restart or a fresh boot anchor', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(3),
        'session_watch_boot_reported_at' => now(),
        'schedule_booted_at' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_steady', 'title' => 'Steady work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_steady' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();
    $service->check();

    // No restart (recent watermark) and no anchor: the sealed marker keeps the
    // boot summary from ever re-emitting, even though a session is working.
    expect($messages)->toBeEmpty()
        ->and(OpencodeSetting::singleton()->session_watch_boot_reported_at)->not->toBeNull();
});

test('does not re-arm the boot summary when the anchor equals the watermark', function () {
    sessionWatcherOwnerChat();
    $anchor = now()->subMinutes(1)->startOfSecond();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => $anchor,
        'session_watch_boot_reported_at' => null,
        'schedule_booted_at' => $anchor,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_equal', 'title' => 'Equal work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_equal' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();
    $service->check();

    // The equal anchor is not newer, so the marker is never re-armed on the
    // second tick: the boot summary is sent exactly once and the watermark is
    // not spuriously reset to now.
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('Sesiones activas desde el inicio del servidor:');

    $settings = OpencodeSetting::singleton();

    expect($settings->session_watch_since->equalTo($anchor))->toBeTrue()
        ->and($settings->session_watch_boot_reported_at)->not->toBeNull();
});

test('does not re-arm the boot summary when an adopted anchor ages past the restart window', function () {
    $chatId = sessionWatcherOwnerChat();
    $anchor = now()->subMinutes(12)->startOfSecond();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => $anchor,
        'session_watch_boot_reported_at' => now(),
        'schedule_booted_at' => $anchor,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_stale_anchor', 'title' => 'Stale anchor work', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: ['ses_stale_anchor' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();
    $service->check();

    // The anchor was already adopted on the first tick (watermark == anchor).
    // Twelve minutes later it is no longer "newer", but the >10-min age
    // heuristic must NOT re-arm the marker: a service running for over ten
    // minutes is not a restart, so no second boot summary may be sent.
    expect($messages)->toBeEmpty();

    $settings = OpencodeSetting::singleton();

    expect($settings->session_watch_since->equalTo($anchor))->toBeTrue()
        ->and($settings->session_watch_boot_reported_at)->not->toBeNull();
});

test('anchored boot summary excludes a waiting session and notifies its question turn', function () {
    sessionWatcherOwnerChat();
    OpencodeSetting::singleton()->update([
        'session_watch_since' => now()->subMinutes(3),
        'session_watch_boot_reported_at' => null,
        'schedule_booted_at' => now()->subMinutes(1),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [
            ['id' => 'ses_anchor_q', 'title' => 'Boot question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
            ['id' => 'ses_work', 'title' => 'Working', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()],
        ],
        states: [
            'ses_anchor_q' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question']),
            'ses_work' => sessionWatcherState(['has_running_part' => true]),
        ],
        conversations: ['ses_anchor_q' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        messages: $messages,
    );

    // The second tick fires the question turn: both sessions are newly
    // discovered on the first tick and only registered, never notified.
    $service->check();
    $service->check();

    $summary = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'Sesiones activas desde el inicio del servidor:'));
    $question = collect($messages)->first(fn (array $m): bool => str_contains($m['text'], 'tiene preguntas.'));

    expect($summary)->not->toBeNull()
        ->and($summary['text'])->toContain('- Working (/home/junior/Projects/DevWarden) — trabajando')
        ->and($summary['text'])->not->toContain('Boot question');

    expect($question)->not->toBeNull()
        ->and($question['text'])->toContain('Boot question')
        ->and($question['text'])->toContain('¿Confirmas la ruta del proyecto?');
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
            ['id' => 'ses_zombie', 'title' => 'Interactive task', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()],
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
        states: ['ses_no_opts' => sessionWatcherState(['has_running_part' => true, 'last_turn_tool' => 'question'])],
        conversations: ['ses_no_opts' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        questionOptions: ['ses_no_opts' => []],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toBe("La sesión de opencode \"No options\" tiene preguntas.\n\n¿Confirmas la ruta del proyecto?\n\nResponde para continuar.")
        ->and($messages[0]['keyboard'])->toBeNull();
});

test('notifies a pending-permission session with its question options as plain text', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_finished_opts',
        'title' => 'Ask with options',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_finished_opts', 'title' => 'Ask with options', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_finished_opts' => sessionWatcherTranscript('¿Confirmas la ruta del proyecto?')],
        permissions: [
            ['sessionId' => 'ses_finished_opts', 'permissionId' => 'perm_1', 'text' => 'run command `ls`'],
        ],
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
        ->and($messages[0]['text'])->not->toContain('Toca un botón para responder.')
        ->and($messages[0]['keyboard'])->toBeNull();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_finished_opts')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();
});

test('notifies a live question turn with the options as plain text', function () {
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
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Live question" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('Sesión: ses_turn_opts')
        ->and($messages[0]['text'])->toContain('Pregunta 1: ¿Aplico los cambios?')
        ->and($messages[0]['text'])->toContain('(a) Aplicar')
        ->and($messages[0]['text'])->toContain('(b) Cancelar')
        ->and($messages[0]['text'])->not->toContain('Toca un botón para responder.')
        ->and($messages[0]['keyboard'])->toBeNull();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_turn_opts')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();
});

test('lists every question of a pending-permission session with its lettered options as plain text', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_multi_opts',
        'title' => 'Multi question',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_multi_opts', 'title' => 'Multi question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_multi_opts' => sessionWatcherTranscript('¿Elige opciones?')],
        permissions: [
            ['sessionId' => 'ses_multi_opts', 'permissionId' => 'perm_1', 'text' => 'run command `ls`'],
        ],
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
        ->and($messages[0]['text'])->toContain('Sesión: ses_multi_opts')
        ->and($messages[0]['text'])->toContain('Pregunta 1: ¿Primera pregunta?')
        ->and($messages[0]['text'])->toContain('(a) Opción A1')
        ->and($messages[0]['text'])->toContain('(b) Opción A2')
        ->and($messages[0]['text'])->toContain('Pregunta 2: ¿Segunda pregunta?')
        ->and($messages[0]['text'])->toContain('(a) Opción B1')
        ->and($messages[0]['text'])->not->toContain('oq:')
        ->and($messages[0]['text'])->not->toContain('Toca un botón para responder.')
        ->and($messages[0]['keyboard'])->toBeNull();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_multi_opts')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('question');
});

test('does not notify during a thinking gap and clears the candidate on a resume', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_gap',
        'title' => 'Gap session',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_gap', 'title' => 'Gap session', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_gap' => sessionWatcherTranscript('Thinking between tool calls...')],
        messages: $messages,
    );

    // First tick lands inside the thinking gap: no running part but very recent
    // activity, so the session only becomes a 'stopping' candidate.
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_gap')->first();

    expect($watch->last_seen_status)->toBe('stopping');

    // The session resumes with a running part: the candidate is discarded
    // silently, no "terminó" was ever sent.
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_gap', 'title' => 'Gap session', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_gap' => sessionWatcherState(['has_running_part' => true])],
        conversations: ['ses_gap' => sessionWatcherTranscript('Thinking between tool calls...')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('working');
});

test('treats a pause while the user composes an answer as a candidate, not a finish', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_pause',
        'title' => 'Paused on question',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
        'last_notified_event' => 'question',
        'notified_at' => now()->subMinute(),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_pause', 'title' => 'Paused on question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_pause' => sessionWatcherTranscript('¿Confirmas la ruta?')],
        messages: $messages,
    );

    // The question was answered in the TUI and the user is composing the next
    // message: recent activity means the stop is only a candidate.
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_pause')->first();

    expect($watch->last_seen_status)->toBe('stopping');

    // The user resumes: a running part returns and the candidate is discarded.
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_pause', 'title' => 'Paused on question', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        states: ['ses_pause' => sessionWatcherState(['has_running_part' => true])],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('working');
});

test('confirms a genuine finish only after the session has stayed idle and never re-notifies', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_real_finish',
        'title' => 'Real finish',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_real_finish', 'title' => 'Real finish', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherFresh()]],
        conversations: ['ses_real_finish' => sessionWatcherTranscript('Everything is done.')],
        messages: $messages,
    );

    // First stop observation: candidate, no notification.
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_real_finish')->first();

    expect($watch->last_seen_status)->toBe('stopping');

    // A later tick sees the same persisted watch with the session idle past
    // the confirmation window: exactly one finished notification.
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_real_finish', 'title' => 'Real finish', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_real_finish' => sessionWatcherTranscript('Everything is done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Real finish" terminó.');

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();

    // A further tick never re-notifies the finished session.
    $service->check();

    expect($messages)->toHaveCount(1);
});

test('does not confirm the finish before the 3-minute window elapses', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_boundary',
        'title' => 'Boundary',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopping',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_boundary', 'title' => 'Boundary', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->subMinutes(3)->addSeconds(30)->getTimestampMs()]],
        conversations: ['ses_boundary' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    // 2m30s idle: still inside the window, the candidate stays 'stopping'.
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_boundary')->first();

    expect($watch->last_seen_status)->toBe('stopping');

    // 3m30s idle: the window has elapsed, the finish is confirmed.
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_boundary', 'title' => 'Boundary', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->subMinutes(3)->subSeconds(30)->getTimestampMs()]],
        conversations: ['ses_boundary' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toContain('terminó.');

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished');
});

test('confirms the finish at exactly the 3-minute boundary of the confirmation window', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_exact_boundary',
        'title' => 'Exact boundary',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopping',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_exact_boundary', 'title' => 'Exact boundary', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => now()->subMinutes(3)->getTimestampMs()]],
        conversations: ['ses_exact_boundary' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    // 3:00 idle exactly: Carbon's diffInMinutes returns the fractional 3.0,
    // which satisfies the >= FINISH_CONFIRM_MINUTES guard at the boundary.
    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Exact boundary" terminó.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_exact_boundary')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('confirms a pending-permission session from a stopping candidate as a question, not a finish', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_perm_confirm',
        'title' => 'Permission confirm',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopping',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_perm_confirm', 'title' => 'Permission confirm', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_perm_confirm' => sessionWatcherTranscript('Waiting on you.')],
        permissions: [
            ['sessionId' => 'ses_perm_confirm', 'permissionId' => 'perm_1', 'text' => 'run command `ls`'],
        ],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId)
        ->and($messages[0]['text'])->toContain('La sesión de opencode "Permission confirm" tiene preguntas.')
        ->and($messages[0]['text'])->toContain('Waiting on you.')
        ->and($messages[0]['text'])->not->toContain('terminó.');

    $watch = OpencodeSessionWatch::where('session_id', 'ses_perm_confirm')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('question')
        ->and($watch->notified_at)->not->toBeNull();
});

test('retries a failed finish confirmation on the next tick from a stopping candidate', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_confirm_retry',
        'title' => 'Confirm retry',
        'chat_id' => $chatId,
        'last_seen_status' => 'stopping',
    ]);

    $messages = [];
    $shouldFail = true;
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_confirm_retry', 'title' => 'Confirm retry', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_confirm_retry' => sessionWatcherTranscript('Done.')],
        onNotify: function () use (&$shouldFail): bool {
            return ! $shouldFail;
        },
        messages: $messages,
    );

    // Confirm condition met but the send fails: the candidate is preserved so
    // the same notification is retried next tick.
    $service->check();

    expect($messages)->toHaveCount(1);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_confirm_retry')->first();

    expect($watch->last_seen_status)->toBe('stopping');

    $shouldFail = false;
    $service->check();

    expect($messages)->toHaveCount(2);

    $watch->refresh();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished')
        ->and($watch->notified_at)->not->toBeNull();
});

test('a fresh stopped session is only registered, never confirmed or notified', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_fresh_stopped',
        'title' => 'Found stopped',
        'chat_id' => $chatId,
        'last_seen_status' => 'unknown',
        'checked_at' => now()->subMinutes(30),
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_fresh_stopped', 'title' => 'Found stopped', 'directory' => '/home/junior/Projects/DevWarden', 'time_updated' => sessionWatcherConfirming()]],
        conversations: ['ses_fresh_stopped' => sessionWatcherTranscript('Old work.')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_fresh_stopped')->first();

    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});
