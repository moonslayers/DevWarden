<?php

namespace Tests\Unit\Ai\Tools\Opencode\Support;

use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\OpencodeSessionManager;

/**
 * In-memory stand-in for OpencodeSessionManager used by the tool tests.
 *
 * Records every orchestration call and returns canned responses so no MCP
 * process is ever spawned. The allowed-project check is a simple switch that
 * tests can toggle, and a pre-set exception simulates opencode failures.
 */
class FakeOpencodeSessionManager extends OpencodeSessionManager
{
    public const SESSION_ID = 'ses_fake123';

    /** @var array<int, array{method: string, directory: string, prompt: string, opts?: array<string, mixed>, sessionId?: string}> */
    public array $calls = [];

    public bool $allowed = true;

    public ?OpencodeException $error = null;

    public array $startResult = ['sessionId' => self::SESSION_ID, 'message' => 'Task dispatched to session: ses_fake123.'];

    public string $replyMessage = 'Reply from opencode.';

    public string $askAnswer = 'Answer from opencode.';

    public string $abortMessage = 'Session ses_fake123 aborted.';

    public function startAsyncSession(string $directory, string $prompt, array $opts = []): array
    {
        $this->record(__FUNCTION__, $directory, $prompt, $opts);
        $this->throwIfError();

        return $this->startResult;
    }

    public function advanceSession(string $sessionId, string $directory, string $prompt, array $opts = []): array
    {
        $this->calls[] = [
            'method' => __FUNCTION__,
            'sessionId' => $sessionId,
            'directory' => $directory,
            'prompt' => $prompt,
            'opts' => $opts,
        ];
        $this->throwIfError();

        return ['sessionId' => $sessionId, 'message' => 'Task dispatched to session: '.$sessionId.'.'];
    }

    public function ask(string $directory, string $prompt, array $opts = []): string
    {
        $this->record(__FUNCTION__, $directory, $prompt, $opts);
        $this->throwIfError();

        return $this->askAnswer;
    }

    public function reply(string $sessionId, string $directory, string $prompt, array $opts = []): array
    {
        $this->calls[] = [
            'method' => __FUNCTION__,
            'sessionId' => $sessionId,
            'directory' => $directory,
            'prompt' => $prompt,
            'opts' => $opts,
        ];
        $this->throwIfError();

        return ['sessionId' => $sessionId, 'message' => $this->replyMessage];
    }

    public function abort(string $sessionId, string $directory): string
    {
        $this->calls[] = [
            'method' => __FUNCTION__,
            'sessionId' => $sessionId,
            'directory' => $directory,
        ];
        $this->throwIfError();

        return $this->abortMessage;
    }

    public function isAllowedProject(string $directory): bool
    {
        return $this->allowed;
    }

    /**
     * Whether a call to the given method was recorded.
     */
    public function called(string $method): bool
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last recorded call to the given method, if any.
     *
     * @return array<string, mixed>|null
     */
    public function lastCall(string $method): ?array
    {
        for ($index = count($this->calls) - 1; $index >= 0; $index--) {
            if ($this->calls[$index]['method'] === $method) {
                return $this->calls[$index];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function record(string $method, string $directory, string $prompt, array $opts = []): void
    {
        $this->calls[] = [
            'method' => $method,
            'directory' => $directory,
            'prompt' => $prompt,
            'opts' => $opts,
        ];
    }

    private function throwIfError(): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }
    }
}
