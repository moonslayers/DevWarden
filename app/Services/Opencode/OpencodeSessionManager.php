<?php

namespace App\Services\Opencode;

use App\Models\OpencodeSetting;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\Exceptions\OpencodeProjectNotAllowed;
use Closure;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Exceptions\ClientException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

/**
 * Wraps the Laravel MCP client against the opencode-mcp server.
 *
 * Every orchestration call passes the target project directory explicitly and
 * extracts data from the plain-text tool responses (structuredContent is always
 * null on this server). A single MCP client is built lazily and shared across
 * calls; injecting a pre-built client or a factory closure makes the manager
 * fully fakeable in tests.
 */
class OpencodeSessionManager
{
    /**
     * StdioTransport idle timeout. opencode tool calls block while the session
     * works, so this must comfortably exceed a single call.
     */
    private const IDLE_TIMEOUT_SECONDS = 180.0;

    private const SESSION_ID_PATTERN = '/ses_[A-Za-z0-9]+/';

    private const FIRE_OPTIONS = ['title', 'providerID', 'modelID', 'variant', 'agent'];

    private const ASK_OPTIONS = ['title', 'providerID', 'modelID', 'variant', 'agent', 'system'];

    private const REPLY_OPTIONS = ['providerID', 'modelID', 'variant', 'agent'];

    private ?Client $client;

    private readonly ?Closure $clientFactory;

    public function __construct(?Client $client = null, ?Closure $clientFactory = null)
    {
        $this->client = $client;
        $this->clientFactory = $clientFactory;
    }

    /**
     * Fire a prompt in a new session and return immediately.
     *
     * @param  array{title?: string, providerID?: string, modelID?: string, variant?: string, agent?: string}  $opts
     * @return array{sessionId: string|null, message: string}
     */
    public function startAsyncSession(string $directory, string $prompt, array $opts = []): array
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_fire', [
            'prompt' => $prompt,
            'directory' => $directory,
            ...$this->pickOptions($opts, self::FIRE_OPTIONS),
        ]));

        return [
            'sessionId' => $this->extractSessionId($text),
            'message' => trim($text),
        ];
    }

    /**
     * Continue an existing session with a new prompt (fire-and-forget).
     *
     * @param  array{title?: string, providerID?: string, modelID?: string, variant?: string, agent?: string}  $opts
     * @return array{sessionId: string|null, message: string}
     */
    public function advanceSession(string $sessionId, string $directory, string $prompt, array $opts = []): array
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_fire', [
            'sessionId' => $sessionId,
            'prompt' => $prompt,
            'directory' => $directory,
            ...$this->pickOptions($opts, self::FIRE_OPTIONS),
        ]));

        return [
            'sessionId' => $this->extractSessionId($text),
            'message' => trim($text),
        ];
    }

    /**
     * Create a session, run the prompt and block until it answers.
     *
     * @param  array{title?: string, providerID?: string, modelID?: string, variant?: string, agent?: string, system?: string}  $opts
     */
    public function ask(string $directory, string $prompt, array $opts = []): string
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_ask', [
            'prompt' => $prompt,
            'directory' => $directory,
            ...$this->pickOptions($opts, self::ASK_OPTIONS),
        ]));

        return trim($text);
    }

    /**
     * Send a blocking reply to an existing session and wait for the answer.
     *
     * @param  array{providerID?: string, modelID?: string, variant?: string, agent?: string}  $opts
     * @return array{sessionId: string|null, message: string}
     */
    public function reply(string $sessionId, string $directory, string $prompt, array $opts = []): array
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_reply', [
            'sessionId' => $sessionId,
            'prompt' => $prompt,
            'directory' => $directory,
            ...$this->pickOptions($opts, self::REPLY_OPTIONS),
        ]));

        return [
            'sessionId' => $this->extractSessionId($text),
            'message' => trim($text),
        ];
    }

    /**
     * Poll a session without blocking.
     *
     * @return array{status: 'running'|'idle'|'unknown', finished: bool, raw: string}
     */
    public function checkSession(string $sessionId, string $directory, bool $detailed = false): array
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_check', [
            'sessionId' => $sessionId,
            'directory' => $directory,
            'detailed' => $detailed,
        ]));

        $finished = preg_match('/Status:\s*\*\*(idle|completed)\*\*/', $text) === 1
            || preg_match('/^Done!\s*$/m', $text) === 1;

        $status = $finished ? 'idle' : ($this->looksRunning($text) ? 'running' : 'unknown');

        return [
            'status' => $status,
            'finished' => $finished,
            'raw' => $text,
        ];
    }

    /**
     * Fetch the plain-text conversation transcript of a session.
     */
    public function conversation(string $sessionId, string $directory, int $limit = 20): string
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_conversation', [
            'sessionId' => $sessionId,
            'directory' => $directory,
            'limit' => $limit,
        ]));

        return trim($text);
    }

    /**
     * List pending permission requests across sessions.
     *
     * The exact text format was not verified during the spike, so parsing is
     * tolerant: each line yields a best-effort array and never throws on
     * unexpected shapes. Returns an empty array when nothing is pending.
     *
     * @return array<int, array{id?: string, sessionId?: string, permissionId?: string, text: string}>
     */
    public function pendingPermissions(): array
    {
        $text = trim($this->toolText($this->callTool('opencode_permission_list', [])));

        if ($text === '' || str_contains($text, 'No pending permission requests')) {
            return [];
        }

        $permissions = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $permissions[] = $this->parsePermissionLine($line);
            }
        }

        return $permissions;
    }

    /**
     * Abort a running session.
     *
     * Note: opencode_session_abort expects the session id under "id", not
     * "sessionId" (verified during the MCP spike).
     */
    public function abort(string $sessionId, string $directory): string
    {
        $this->assertAllowedProject($directory);

        $text = $this->toolText($this->callTool('opencode_session_abort', [
            'id' => $sessionId,
            'directory' => $directory,
        ]));

        return trim($text);
    }

    /**
     * Validate that a directory is strictly inside the configured root projects path.
     */
    public function assertAllowedProject(string $directory): void
    {
        if (! $this->isAllowedProject($directory)) {
            throw new OpencodeProjectNotAllowed($directory, (string) OpencodeSetting::singleton()->root_projects_path);
        }
    }

    /**
     * Whether a directory is strictly inside the configured root projects path.
     */
    public function isAllowedProject(string $directory): bool
    {
        $root = $this->normalizePath((string) OpencodeSetting::singleton()->root_projects_path);
        $target = $this->normalizePath($directory);

        if ($target === $root) {
            return false;
        }

        return str_starts_with($target, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Close the shared MCP client, if one was created (idempotent).
     */
    public function disconnect(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Lazily resolve the shared MCP client.
     */
    protected function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->client = $this->clientFactory !== null
            ? ($this->clientFactory)()
            : $this->buildClient();

        return $this->client;
    }

    protected function buildClient(): Client
    {
        return Client::local(...$this->resolveCommand())->withTimeout(self::IDLE_TIMEOUT_SECONDS);
    }

    /**
     * Resolve the opencode-mcp launch command into a binary and its arguments.
     *
     * The configured mcp_command may carry arguments (e.g. "opencode-mcp --debug"),
     * so it is split on whitespace: the first token is the binary verified against
     * the PATH, the remaining tokens are passed as arguments to Client::local().
     * When the configured command is empty, or the binary is not on the PATH and
     * the command has no arguments, it falls back to npx -y opencode-mcp.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function resolveCommand(): array
    {
        $configured = trim((string) OpencodeSetting::singleton()->mcp_command);

        if ($configured === '') {
            return ['npx', ['-y', 'opencode-mcp']];
        }

        $parts = preg_split('/\s+/', $configured) ?: [];
        $binary = $parts[0];
        $args = array_slice($parts, 1);

        if ($this->binaryOnPath($binary) || $args !== []) {
            return [$binary, $args];
        }

        return ['npx', ['-y', 'opencode-mcp']];
    }

    protected function binaryOnPath(string $command): bool
    {
        try {
            $process = Process::fromShellCommandline('command -v '.escapeshellarg($command));
            $process->run();
        } catch (RuntimeException) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Extract the opencode session id from a tool response.
     */
    protected function extractSessionId(string $text): ?string
    {
        if (preg_match(self::SESSION_ID_PATTERN, $text, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * Best-effort extraction of structured fields from a permission list line.
     * Never throws: whatever cannot be parsed is left out, the raw line is kept.
     *
     * @return array{id?: string, sessionId?: string, permissionId?: string, text: string}
     */
    protected function parsePermissionLine(string $line): array
    {
        $permission = ['text' => $line];

        if (preg_match('/\bses_[A-Za-z0-9]+/', $line, $matches) === 1) {
            $permission['sessionId'] = $matches[0];
        }

        if (preg_match('/\b(?:perm|permission)[_-][A-Za-z0-9_-]+/', $line, $matches) === 1) {
            $permission['permissionId'] = $matches[0];
        }

        if (preg_match('/\b(?:id|requestId|request_id)[:\s]+([A-Za-z0-9_-]+)/i', $line, $matches) === 1) {
            $permission['id'] = $matches[1];
        }

        return $permission;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function pickOptions(array $opts, array $keys): array
    {
        return array_filter(
            array_intersect_key($opts, array_flip($keys)),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    protected function toolText(ToolResult $result): string
    {
        $text = $result->text();

        if ($result->isError) {
            throw new OpencodeException("opencode-mcp tool error: {$text}");
        }

        return $text;
    }

    /**
     * Run a tool call, converting MCP transport exceptions into OpencodeException
     * so tools and the monitor only ever handle a single exception type.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function callTool(string $tool, array $arguments): ToolResult
    {
        try {
            return $this->client()->callTool($tool, $arguments);
        } catch (ClientException|JsonRpcException $exception) {
            throw new OpencodeException(
                "opencode-mcp MCP transport error calling [{$tool}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    protected function looksRunning(string $text): bool
    {
        return preg_match('/Status:\s*\*\*(running|pending)\*\*/', $text) === 1;
    }

    protected function normalizePath(string $path): string
    {
        $resolved = realpath($path);

        return $resolved !== false
            ? rtrim($resolved, DIRECTORY_SEPARATOR)
            : rtrim(Path::normalize($path), DIRECTORY_SEPARATOR);
    }
}
