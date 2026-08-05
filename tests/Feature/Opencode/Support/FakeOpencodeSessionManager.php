<?php

namespace Tests\Feature\Opencode\Support;

use App\Services\Opencode\Exceptions\OpencodeException;
use Tests\Unit\Ai\Tools\Opencode\Support\FakeOpencodeSessionManager as UnitFakeOpencodeSessionManager;

/**
 * Feature-level fake for OpencodeSessionManager.
 *
 * Extends the Unit fake (which records the orchestration calls) and adds the
 * read-only polling calls used by the opencode:monitor command, keyed by
 * session id, so a full workflow lifecycle can be exercised without any MCP
 * process or real Telegram call.
 */
class FakeOpencodeSessionManager extends UnitFakeOpencodeSessionManager
{
    /**
     * @var array<string, array{status: string, finished: bool, raw: string}>
     */
    public array $checkResults = [];

    /**
     * The conversation transcript returned for every session.
     */
    public string $conversation = '';

    public ?OpencodeException $checkError = null;

    public function checkSession(string $sessionId, string $directory, bool $detailed = false): array
    {
        if ($this->checkError !== null) {
            throw $this->checkError;
        }

        return $this->checkResults[$sessionId]
            ?? ['status' => 'idle', 'finished' => true, 'raw' => 'Status: **idle**'];
    }

    public function conversation(string $sessionId, string $directory, int $limit = 20): string
    {
        return $this->conversation;
    }

    public function disconnect(): void
    {
        // No real MCP client was ever opened.
    }
}
