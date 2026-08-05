<?php

use App\Models\BotSetting;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\Exceptions\OpencodeProjectNotAllowed;
use App\Services\Opencode\OpencodeNotifier;
use App\Services\Opencode\OpencodeSessionManager;
use App\Services\Opencode\OpencodeSessionParser;
use App\Services\Opencode\OpencodeSessionWatcher;
use Illuminate\Support\Carbon;

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
 * A completed Tasks line without an "in progress" clause.
 *
 * @return array{all_tasks_completed: bool, tasks_line: string, raw: string}
 */
function sessionWatcherAllDone(string $tasksLine = 'Tasks: 5/5 completed'): array
{
    return ['all_tasks_completed' => true, 'tasks_line' => $tasksLine, 'raw' => "Status: **idle**\n\n{$tasksLine}\n\nDone!"];
}

/**
 * A Tasks line with an "in progress" clause (session still working).
 *
 * @return array{all_tasks_completed: bool, tasks_line: string, raw: string}
 */
function sessionWatcherInProgress(string $tasksLine = 'Tasks: 8/9 completed, 1 in progress'): array
{
    return ['all_tasks_completed' => false, 'tasks_line' => $tasksLine, 'raw' => "Status: **idle**\n\n{$tasksLine}\n\nDone!"];
}

/**
 * Build the watcher service with a mocked manager and notifier. Every
 * notification is appended to $messages (passed by reference).
 *
 * @param  array<int, array{id: string, title: string, status: string}>  $sessions
 * @param  array<string, array{id: string, title: string|null, directory: string|null, updated_at?: Carbon|null}>  $sessionInfos
 * @param  array<string, array{all_tasks_completed: bool, tasks_line: string|null, raw: string}>  $sessionProgresses
 * @param  array<int, array{id?: string, sessionId?: string, permissionId?: string, text: string}>  $permissions
 * @param  array<string, string>  $conversations
 * @param  array<int, array{chat_id: int, text: string}>  $messages
 */
function sessionWatcherService(array $sessions, array $sessionInfos = [], array $permissions = [], array $conversations = [], array $sessionProgresses = [], ?callable $onNotify = null, ?array &$messages = null): OpencodeSessionWatcher
{
    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('listSessions')->andReturn($sessions);
    $manager->shouldReceive('pendingPermissions')->andReturn($permissions);
    $manager->shouldReceive('sessionInfo')->andReturnUsing(
        fn (string $id): array => $sessionInfos[$id] ?? ['id' => $id, 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()],
    );
    $manager->shouldReceive('sessionProgress')->andReturnUsing(
        fn (string $id): array => $sessionProgresses[$id] ?? sessionWatcherInProgress(),
    );
    $manager->shouldReceive('conversation')->andReturnUsing(
        fn (string $id): string => $conversations[$id] ?? '',
    );

    $messages ??= [];
    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldReceive('notify')->andReturnUsing(
        function (int $chatId, string $markdown) use (&$messages, $onNotify): bool {
            $messages[] = ['chat_id' => $chatId, 'text' => $markdown];

            return $onNotify === null ? true : $onNotify($chatId, $markdown);
        },
    );

    return new OpencodeSessionWatcher($manager, $notifier, new OpencodeSessionParser);
}

function sessionWatcherTranscript(string $assistant): string
{
    return "--- Message 1 [user] ---\nGo.\n\n--- Message 2 [assistant] ---\n{$assistant}";
}

test('does nothing when no owner is configured', function () {
    $service = sessionWatcherService([
        ['id' => 'ses_ext1', 'title' => 'External task', 'status' => 'idle'],
    ]);

    $service->check();

    expect(OpencodeSessionWatch::count())->toBe(0);
});

test('does nothing when the owner has no telegram conversation', function () {
    $user = User::factory()->create();
    BotSetting::singleton()->update(['owner_user_id' => $user->id]);

    $service = sessionWatcherService([
        ['id' => 'ses_ext1', 'title' => 'External task', 'status' => 'idle'],
    ]);

    $service->check();

    expect(OpencodeSessionWatch::count())->toBe(0);
});

test('registers a newly discovered session without notifying', function () {
    $chatId = sessionWatcherOwnerChat();

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_new', 'title' => 'Fresh session', 'status' => 'idle']],
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

test('stays working and does not notify while tasks are in progress', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'title' => 'Build feature',
        'chat_id' => $chatId,
        'last_seen_status' => 'unknown',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_work', 'title' => 'Build feature', 'status' => 'idle']],
        sessionProgresses: ['ses_work' => sessionWatcherInProgress()],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_work')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->checked_at)->not->toBeNull();
});

test('notifies finished when a session transitions from working to all tasks completed', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_work',
        'title' => 'Build feature',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_work', 'title' => 'Build feature', 'status' => 'idle']],
        sessionInfos: ['ses_work' => ['id' => 'ses_work', 'title' => 'Build feature', 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_work' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_q', 'title' => 'Ask me', 'status' => 'idle']],
        sessionInfos: ['ses_q' => ['id' => 'ses_q', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_q' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_perm', 'title' => 'Needs permission', 'status' => 'idle']],
        sessionInfos: ['ses_perm' => ['id' => 'ses_perm', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_perm' => sessionWatcherAllDone()],
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

test('notifies when the raw check output reports an error', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_err',
        'title' => 'Failing task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_err', 'title' => 'Failing task', 'status' => 'idle']],
        sessionInfos: ['ses_err' => ['id' => 'ses_err', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_err' => ['all_tasks_completed' => false, 'tasks_line' => null, 'raw' => 'Status: **error**']],
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

test('does not re-notify an error on a later tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_err_once',
        'title' => 'Failing task',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_err_once', 'title' => 'Failing task', 'status' => 'idle']],
        sessionInfos: ['ses_err_once' => ['id' => 'ses_err_once', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_err_once' => ['all_tasks_completed' => false, 'tasks_line' => null, 'raw' => 'Status: **error**']],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1);
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
        sessions: [['id' => 'ses_stable', 'title' => 'Stable', 'status' => 'idle']],
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

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('listSessions')->andReturn([
        ['id' => 'ses_skipped', 'title' => 'Already stopped', 'status' => 'idle'],
    ]);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldNotReceive('sessionInfo');
    $manager->shouldNotReceive('sessionProgress');
    $manager->shouldNotReceive('conversation');

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($manager, $notifier, new OpencodeSessionParser);

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
        sessions: [['id' => 'ses_again', 'title' => 'Round two', 'status' => 'idle']],
        sessionInfos: ['ses_again' => ['id' => 'ses_again', 'title' => 'Round two', 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_again' => sessionWatcherInProgress()],
        conversations: ['ses_again' => sessionWatcherTranscript('Part two done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty();
    expect(OpencodeSessionWatch::where('session_id', 'ses_again')->first()->last_seen_status)->toBe('working');

    $service = sessionWatcherService(
        sessions: [['id' => 'ses_again', 'title' => 'Round two', 'status' => 'idle']],
        sessionInfos: ['ses_again' => ['id' => 'ses_again', 'title' => 'Round two', 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_again' => sessionWatcherAllDone()],
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

test('bootstrap notifies once for a fresh already-stopped session never notified before', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_fresh',
        'title' => 'Fresh done',
        'chat_id' => $chatId,
        'last_seen_status' => 'idle',
        'last_notified_event' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_fresh', 'title' => 'Fresh done', 'status' => 'idle']],
        sessionInfos: ['ses_fresh' => ['id' => 'ses_fresh', 'title' => 'Fresh done', 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_fresh' => sessionWatcherAllDone()],
        conversations: ['ses_fresh' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();
    $service->check();

    expect($messages)->toHaveCount(1);

    $watch = OpencodeSessionWatch::where('session_id', 'ses_fresh')->first();
    expect($watch->last_seen_status)->toBe('stopped')
        ->and($watch->last_notified_event)->toBe('finished');
});

test('does not notify an old stopped session never notified before (anti-spam for history)', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_old',
        'title' => 'Old done',
        'chat_id' => $chatId,
        'last_seen_status' => 'idle',
        'last_notified_event' => null,
    ]);

    $messages = [];
    $service = sessionWatcherService(
        sessions: [['id' => 'ses_old', 'title' => 'Old done', 'status' => 'idle']],
        sessionInfos: ['ses_old' => ['id' => 'ses_old', 'title' => 'Old done', 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()->subDays(2)]],
        sessionProgresses: ['ses_old' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_workflow', 'title' => 'Workflow task', 'status' => 'idle']],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toBeEmpty()
        ->and(OpencodeSessionWatch::where('session_id', 'ses_workflow')->exists())->toBeFalse();
});

test('skips a session whose directory is outside the allowed projects without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_outside',
        'title' => 'Rogue',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('listSessions')->andReturn([
        ['id' => 'ses_outside', 'title' => 'Rogue', 'status' => 'idle'],
    ]);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldReceive('sessionInfo')
        ->andThrow(new OpencodeProjectNotAllowed('/etc/outside', '/home/junior/Projects'));

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($manager, $notifier, new OpencodeSessionParser);

    $service->check();

    expect(OpencodeSessionWatch::where('session_id', 'ses_outside')->first()->last_seen_status)->toBe('working');
});

test('keeps the previous status when sessionInfo throws a transport error without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_transport',
        'title' => 'Transport fail',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('listSessions')->andReturn([
        ['id' => 'ses_transport', 'title' => 'Transport fail', 'status' => 'idle'],
    ]);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldReceive('sessionInfo')
        ->andThrow(new OpencodeException('opencode-mcp MCP transport error calling [opencode_session_get]: boom'));

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_transport')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull()
        ->and($watch->notified_at)->toBeNull();
});

test('keeps the previous status when sessionProgress throws a transport error without breaking the tick', function () {
    $chatId = sessionWatcherOwnerChat();
    OpencodeSessionWatch::factory()->create([
        'session_id' => 'ses_prog_transport',
        'title' => 'Progress fail',
        'chat_id' => $chatId,
        'last_seen_status' => 'working',
    ]);

    $manager = mock(OpencodeSessionManager::class);
    $manager->shouldReceive('listSessions')->andReturn([
        ['id' => 'ses_prog_transport', 'title' => 'Progress fail', 'status' => 'idle'],
    ]);
    $manager->shouldReceive('pendingPermissions')->andReturn([]);
    $manager->shouldReceive('sessionInfo')->andReturn([
        'id' => 'ses_prog_transport', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now(),
    ]);
    $manager->shouldReceive('sessionProgress')
        ->andThrow(new OpencodeException('opencode-mcp MCP transport error calling [opencode_check]: boom'));

    $notifier = mock(OpencodeNotifier::class);
    $notifier->shouldNotReceive('notify');

    $service = new OpencodeSessionWatcher($manager, $notifier, new OpencodeSessionParser);

    $service->check();

    $watch = OpencodeSessionWatch::where('session_id', 'ses_prog_transport')->first();

    expect($watch->last_seen_status)->toBe('working')
        ->and($watch->last_notified_event)->toBeNull();
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
        sessions: [['id' => 'ses_retry', 'title' => 'Retry me', 'status' => 'idle']],
        sessionInfos: ['ses_retry' => ['id' => 'ses_retry', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_retry' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_retry_ok', 'title' => 'Retry then win', 'status' => 'idle']],
        sessionInfos: ['ses_retry_ok' => ['id' => 'ses_retry_ok', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_retry_ok' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_q_once', 'title' => 'Ask once', 'status' => 'idle']],
        sessionInfos: ['ses_q_once' => ['id' => 'ses_q_once', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_q_once' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_stale_check', 'title' => 'Checked long ago', 'status' => 'idle']],
        sessionInfos: ['ses_stale_check' => ['id' => 'ses_stale_check', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_stale_check' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_chatpick', 'title' => 'Chat pick', 'status' => 'idle']],
        sessionInfos: ['ses_chatpick' => ['id' => 'ses_chatpick', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_chatpick' => sessionWatcherAllDone()],
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
        sessions: [['id' => 'ses_fallback', 'title' => 'Fallback', 'status' => 'idle']],
        sessionInfos: ['ses_fallback' => ['id' => 'ses_fallback', 'title' => null, 'directory' => '/home/junior/Projects/DevWarden', 'updated_at' => now()]],
        sessionProgresses: ['ses_fallback' => sessionWatcherAllDone()],
        conversations: ['ses_fallback' => sessionWatcherTranscript('Done.')],
        messages: $messages,
    );

    $service->check();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['chat_id'])->toBe($chatId);
});
