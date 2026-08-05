<?php

use App\Models\OpencodeSetting;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\Exceptions\OpencodeProjectNotAllowed;
use App\Services\Opencode\OpencodeSessionManager;
use Carbon\Carbon;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Client\Transport\StdioTransport;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Throwable;

/**
 * Fake MCP client that never spawns a process: it returns canned text per tool
 * name and records every call for assertions.
 */
class FakeMcpClient extends Client
{
    /** @var array<string, string> */
    public array $responses = [];

    /** @var array<string, string> */
    public array $errors = [];

    /** @var array<string, Throwable> */
    public array $exceptions = [];

    /** @var array<int, array{name: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct(new StdioTransport('false', []));
    }

    public function callTool(string $name, array $arguments = []): ToolResult
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];

        if (isset($this->exceptions[$name])) {
            throw $this->exceptions[$name];
        }

        $isError = isset($this->errors[$name]);

        return new ToolResult(
            content: [['type' => 'text', 'text' => $this->responses[$name] ?? $this->errors[$name] ?? '']],
            isError: $isError,
        );
    }
}

beforeEach(function () {
    OpencodeSetting::factory()->create([
        'root_projects_path' => '/home/junior/Projects',
        'mcp_command' => 'opencode-mcp',
    ]);
});

function fakeManager(FakeMcpClient $fake): OpencodeSessionManager
{
    return new OpencodeSessionManager($fake);
}

/**
 * Exposes the protected command resolution and builds the real MCP client
 * without spawning a process: binaryOnPath() is stubbed and buildClient()
 * only constructs the Client (it connects lazily).
 */
class CommandResolutionSessionManager extends OpencodeSessionManager
{
    /** @var list<string> */
    public array $pathBinaries = ['opencode-mcp', 'opencode'];

    public function resolveCommandPublic(): array
    {
        return $this->resolveCommand();
    }

    public function buildClientRecipe(): array
    {
        $client = $this->buildClient();
        $transport = (new ReflectionProperty(Client::class, 'transport'))->getValue($client);

        return $transport->recipe();
    }

    protected function binaryOnPath(string $command): bool
    {
        return in_array($command, $this->pathBinaries, true);
    }
}

test('isAllowedProject accepts a project inside the root and rejects root, siblings and parents', function () {
    $fake = new FakeMcpClient;
    $manager = fakeManager($fake);

    expect($manager->isAllowedProject('/home/junior/Projects/devwarden'))->toBeTrue()
        ->and($manager->isAllowedProject('/home/junior/Projects/DevWarden/packages/demo'))->toBeTrue()
        ->and($manager->isAllowedProject('/home/junior/Projects'))->toBeFalse()
        ->and($manager->isAllowedProject('/home/junior'))->toBeFalse()
        ->and($manager->isAllowedProject('/home/junior/ProjectsOther'))->toBeFalse()
        ->and($manager->isAllowedProject('/etc'))->toBeFalse();
});

test('assertAllowedProject throws OpencodeProjectNotAllowed for a disallowed directory', function () {
    $fake = new FakeMcpClient;
    $manager = fakeManager($fake);

    expect(fn () => $manager->assertAllowedProject('/etc/passwd'))
        ->toThrow(OpencodeProjectNotAllowed::class, '/home/junior/Projects');
});

test('startAsyncSession fires a new session, passes the directory and extracts the session id', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_fire'] = 'Task dispatched to session: ses_abc123XYZ.';

    $result = fakeManager($fake)->startAsyncSession('/home/junior/Projects/DevWarden', 'build the feature', [
        'agent' => 'builder',
        'title' => 'Task title',
    ]);

    expect($result)->toBe(['sessionId' => 'ses_abc123XYZ', 'message' => 'Task dispatched to session: ses_abc123XYZ.']);

    $call = $fake->calls[0];
    expect($call['name'])->toBe('opencode_fire')
        ->and($call['arguments'])->toMatchArray([
            'prompt' => 'build the feature',
            'directory' => '/home/junior/Projects/DevWarden',
            'agent' => 'builder',
            'title' => 'Task title',
        ]);
});

test('startAsyncSession returns a null session id when the response has none', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_fire'] = 'No session here.';

    $result = fakeManager($fake)->startAsyncSession('/home/junior/Projects/DevWarden', 'hi');

    expect($result['sessionId'])->toBeNull();
});

test('advanceSession continues the same session by passing its sessionId', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_fire'] = 'Task dispatched to session: ses_xyz987.';

    $result = fakeManager($fake)->advanceSession('ses_xyz987', '/home/junior/Projects/DevWarden', 'keep going');

    expect($result['sessionId'])->toBe('ses_xyz987');

    expect($fake->calls[0]['arguments'])->toMatchArray([
        'sessionId' => 'ses_xyz987',
        'prompt' => 'keep going',
        'directory' => '/home/junior/Projects/DevWarden',
    ]);
});

test('ask runs a blocking prompt and returns the trimmed text', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_ask'] = '  The answer is 42.  ';

    $text = fakeManager($fake)->ask('/home/junior/Projects/DevWarden', 'What is 2+2?', ['system' => 'Be terse.']);

    expect($text)->toBe('The answer is 42.');

    expect($fake->calls[0]['arguments'])->toMatchArray([
        'prompt' => 'What is 2+2?',
        'directory' => '/home/junior/Projects/DevWarden',
        'system' => 'Be terse.',
    ]);
});

test('reply sends a blocking reply to an existing session', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_reply'] = 'Task dispatched to session: ses_reply1.';

    $result = fakeManager($fake)->reply('ses_reply1', '/home/junior/Projects/DevWarden', 'please continue');

    expect($result)->toBe(['sessionId' => 'ses_reply1', 'message' => 'Task dispatched to session: ses_reply1.'])
        ->and($fake->calls[0]['name'])->toBe('opencode_reply')
        ->and($fake->calls[0]['arguments'])->toMatchArray([
            'sessionId' => 'ses_reply1',
            'prompt' => 'please continue',
            'directory' => '/home/junior/Projects/DevWarden',
        ]);
});

test('checkSession maps idle text to status idle and finished true', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = "Status: **idle**\n\nDone!";

    $result = fakeManager($fake)->checkSession('ses_done', '/home/junior/Projects/DevWarden');

    expect($result)->toMatchArray(['status' => 'idle', 'finished' => true]);
});

test('checkSession maps completed text to status idle', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = 'Status: **completed**';

    $result = fakeManager($fake)->checkSession('ses_done', '/home/junior/Projects/DevWarden');

    expect($result['status'])->toBe('idle')->and($result['finished'])->toBeTrue();
});

test('checkSession maps running and pending text to status running', function () {
    $fake = new FakeMcpClient;
    $manager = fakeManager($fake);

    $fake->responses['opencode_check'] = 'Status: **running**';
    expect($manager->checkSession('ses_run', '/home/junior/Projects/DevWarden'))
        ->toMatchArray(['status' => 'running', 'finished' => false]);

    $fake->responses['opencode_check'] = 'Status: **pending**';
    expect($manager->checkSession('ses_pending', '/home/junior/Projects/DevWarden'))
        ->toMatchArray(['status' => 'running', 'finished' => false]);
});

test('checkSession returns unknown for unrecognized text and keeps the raw payload', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = 'Something else entirely.';

    $result = fakeManager($fake)->checkSession('ses_weird', '/home/junior/Projects/DevWarden');

    expect($result)->toMatchArray(['status' => 'unknown', 'finished' => false, 'raw' => 'Something else entirely.']);
});

test('checkSession forwards the detailed flag', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = 'Status: **idle**';

    fakeManager($fake)->checkSession('ses_det', '/home/junior/Projects/DevWarden', detailed: true);

    expect($fake->calls[0]['arguments'])->toMatchArray([
        'sessionId' => 'ses_det',
        'directory' => '/home/junior/Projects/DevWarden',
        'detailed' => true,
    ]);
});

test('sessionProgress is not complete while the tasks line has an in progress clause', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = "Status: **idle**\n\nTasks: 8/9 completed, 1 in progress\n\nDone!";

    $result = fakeManager($fake)->sessionProgress('ses_work', '/home/junior/Projects/DevWarden');

    expect($result)->toMatchArray([
        'all_tasks_completed' => false,
        'tasks_line' => 'Tasks: 8/9 completed, 1 in progress',
    ]);

    expect($fake->calls[0]['arguments'])->toMatchArray([
        'sessionId' => 'ses_work',
        'directory' => '/home/junior/Projects/DevWarden',
        'detailed' => false,
    ]);
});

test('sessionProgress is complete when the tasks line has no in progress clause', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = "Status: **idle**\n\nTasks: 5/5 completed\n\nDone!";

    $result = fakeManager($fake)->sessionProgress('ses_done', '/home/junior/Projects/DevWarden');

    expect($result)->toMatchArray([
        'all_tasks_completed' => true,
        'tasks_line' => 'Tasks: 5/5 completed',
    ]);
});

test('sessionProgress is conservative and not complete when there is no tasks line', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_check'] = 'Status: **running**';

    $result = fakeManager($fake)->sessionProgress('ses_weird', '/home/junior/Projects/DevWarden');

    expect($result)->toMatchArray([
        'all_tasks_completed' => false,
        'tasks_line' => null,
    ])->and($result['raw'])->toBe('Status: **running**');
});

test('conversation returns the trimmed transcript with a limit', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_conversation'] = "--- Message 1 [user] ---\nhi\n--- Message 2 [assistant] ---\nhello\n";

    $text = fakeManager($fake)->conversation('ses_conv', '/home/junior/Projects/DevWarden', limit: 5);

    expect($text)->toContain('hello');

    expect($fake->calls[0]['arguments'])->toMatchArray([
        'sessionId' => 'ses_conv',
        'directory' => '/home/junior/Projects/DevWarden',
        'limit' => 5,
    ]);
});

test('listSessions parses the overview header and session lines', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_sessions_overview'] = <<<'TXT'
## Sessions (3)
- [busy] Building the thing [ses_abc123]
- [idle] Another thing [ses_def456] (child of ses_abc123)
- [busy] Third [ses_ghi789]
TXT;

    $result = fakeManager($fake)->listSessions();

    expect($result)->toBe([
        ['id' => 'ses_abc123', 'title' => 'Building the thing', 'status' => 'busy'],
        ['id' => 'ses_def456', 'title' => 'Another thing', 'status' => 'idle'],
        ['id' => 'ses_ghi789', 'title' => 'Third', 'status' => 'busy'],
    ]);

    expect($fake->calls[0])->toMatchArray(['name' => 'opencode_sessions_overview', 'arguments' => []]);
});

test('listSessions returns an empty array when there are no sessions', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_sessions_overview'] = "## Sessions (0)\nNo sessions found.";

    expect(fakeManager($fake)->listSessions())->toBe([]);
});

test('sessionInfo parses ID, Title, Slug, Directory and Updated and passes the session id', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_session_get'] = <<<'TXT'
ID: ses_abc123
Title: Build the feature
Slug: build-the-feature
Created: 2026-08-05T10:00:00Z
Updated: 2026-08-05T10:30:00Z
Version: 4
Directory: /home/junior/Projects/DevWarden
TXT;

    $result = fakeManager($fake)->sessionInfo('ses_abc123');

    expect($result)->toMatchArray([
        'id' => 'ses_abc123',
        'title' => 'Build the feature',
        'directory' => '/home/junior/Projects/DevWarden',
    ])
        ->and($result['updated_at'])->not->toBeNull()
        ->and($result['updated_at']->equalTo(Carbon::parse('2026-08-05T10:30:00Z')))->toBeTrue();

    expect($fake->calls[0])->toMatchArray(['name' => 'opencode_session_get', 'arguments' => ['id' => 'ses_abc123']]);
});

test('sessionInfo leaves updated_at null when the Updated line is missing or unparseable', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_session_get'] = <<<'TXT'
ID: ses_abc123
Title: Build the feature
Updated: not-a-date
Directory: /home/junior/Projects/DevWarden
TXT;

    $result = fakeManager($fake)->sessionInfo('ses_abc123');

    expect($result['updated_at'])->toBeNull();
});

test('sessionInfo throws OpencodeProjectNotAllowed when the directory is outside the root', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_session_get'] = <<<'TXT'
ID: ses_abc123
Title: Rogue session
Directory: /etc/something
TXT;

    expect(fn () => fakeManager($fake)->sessionInfo('ses_abc123'))
        ->toThrow(OpencodeProjectNotAllowed::class, '/etc/something');
});

test('pendingPermissions returns an empty array when nothing is pending', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_permission_list'] = 'No pending permission requests.';

    expect(fakeManager($fake)->pendingPermissions())->toBe([]);
});

test('pendingPermissions parses pending requests best-effort without throwing', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_permission_list'] = <<<'TXT'
- Request perm_abc123 for session ses_xyz789: run command `ls`
- weird unparseable line
TXT;

    $result = fakeManager($fake)->pendingPermissions();

    expect($result[0])->toMatchArray([
        'sessionId' => 'ses_xyz789',
        'permissionId' => 'perm_abc123',
        'text' => '- Request perm_abc123 for session ses_xyz789: run command `ls`',
    ])
        ->and($result[1])->toBe(['text' => '- weird unparseable line']);
});

test('abort uses the id argument, not sessionId', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_session_abort'] = 'Session ses_abort aborted.';

    $message = fakeManager($fake)->abort('ses_abort', '/home/junior/Projects/DevWarden');

    expect($message)->toBe('Session ses_abort aborted.');

    $call = $fake->calls[0];
    expect($call['name'])->toBe('opencode_session_abort')
        ->and($call['arguments'])->toBe(['id' => 'ses_abort', 'directory' => '/home/junior/Projects/DevWarden'])
        ->and($call['arguments'])->not->toHaveKey('sessionId');
});

test('optional options are only forwarded when present', function () {
    $fake = new FakeMcpClient;
    $fake->responses['opencode_fire'] = 'Task dispatched to session: ses_opts1.';

    fakeManager($fake)->startAsyncSession('/home/junior/Projects/DevWarden', 'hi', [
        'agent' => 'builder',
        'providerID' => null,
        'bogusKey' => 'should be dropped',
    ]);

    expect($fake->calls[0]['arguments'])->toHaveKey('agent')
        ->not->toHaveKey('providerID')
        ->not->toHaveKey('bogusKey');
});

test('an isError tool result throws OpencodeException', function () {
    $fake = new FakeMcpClient;
    $fake->errors['opencode_check'] = 'boom';

    expect(fn () => fakeManager($fake)->checkSession('ses_err', '/home/junior/Projects/DevWarden'))
        ->toThrow(OpencodeException::class, 'boom');
});

test('a ClientException from the MCP transport is rethrown as OpencodeException with context', function () {
    $fake = new FakeMcpClient;
    $fake->exceptions['opencode_check'] = new ClientException('The connection timed out.');

    expect(fn () => fakeManager($fake)->checkSession('ses_timeout', '/home/junior/Projects/DevWarden'))
        ->toThrow(OpencodeException::class, 'opencode_check')
        ->toThrow(OpencodeException::class, 'The connection timed out.')
        ->toThrow(OpencodeException::class, 'MCP transport error');
});

test('a JsonRpcException from the MCP transport is rethrown as OpencodeException with context', function () {
    $fake = new FakeMcpClient;
    $fake->exceptions['opencode_fire'] = new JsonRpcException('Tool not found: opencode_fire', -32601);

    expect(fn () => fakeManager($fake)->startAsyncSession('/home/junior/Projects/DevWarden', 'hi'))
        ->toThrow(OpencodeException::class, 'opencode_fire')
        ->toThrow(OpencodeException::class, 'Tool not found')
        ->toThrow(OpencodeException::class, 'MCP transport error');
});

test('a transport failure on abort is rethrown as OpencodeException, protecting the tool caller', function () {
    $fake = new FakeMcpClient;
    $fake->exceptions['opencode_session_abort'] = new ClientException('Process exited with code 1.');

    expect(fn () => fakeManager($fake)->abort('ses_dead', '/home/junior/Projects/DevWarden'))
        ->toThrow(OpencodeException::class, 'Process exited with code 1.');
});

test('disconnect is idempotent and leaves the injected client disconnected', function () {
    $fake = new FakeMcpClient;
    $manager = fakeManager($fake);

    $manager->disconnect();
    $manager->disconnect();

    expect(true)->toBeTrue();
});

test('resolveCommand splits the configured command into binary and arguments', function () {
    OpencodeSetting::singleton()->update(['mcp_command' => 'opencode-mcp --debug']);

    $manager = new CommandResolutionSessionManager;

    expect($manager->resolveCommandPublic())->toBe(['opencode-mcp', ['--debug']]);
});

test('resolveCommand forwards the binary and its arguments to the built client', function () {
    OpencodeSetting::singleton()->update(['mcp_command' => 'opencode serve --port 4444 --verbose']);

    $manager = new CommandResolutionSessionManager;

    expect($manager->buildClientRecipe())
        ->toMatchArray(['command' => 'opencode', 'args' => ['serve', '--port', '4444', '--verbose']]);
});

test('resolveCommand uses the configured command with arguments even when the binary is missing', function () {
    OpencodeSetting::singleton()->update(['mcp_command' => 'missing-binary --debug']);

    $manager = new CommandResolutionSessionManager;

    expect($manager->resolveCommandPublic())->toBe(['missing-binary', ['--debug']]);
});

test('resolveCommand falls back to npx when the binary is missing and there are no arguments', function () {
    OpencodeSetting::singleton()->update(['mcp_command' => 'missing-binary']);

    $manager = new CommandResolutionSessionManager;

    expect($manager->resolveCommandPublic())->toBe(['npx', ['-y', 'opencode-mcp']]);
});

test('resolveCommand falls back to npx when no mcp command is configured', function () {
    OpencodeSetting::singleton()->update(['mcp_command' => '   ']);

    $manager = new CommandResolutionSessionManager;

    expect($manager->resolveCommandPublic())->toBe(['npx', ['-y', 'opencode-mcp']]);
});
