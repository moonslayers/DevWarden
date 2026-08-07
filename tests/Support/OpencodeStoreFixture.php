<?php

namespace Tests\Support;

use PDO;

/**
 * Builds temporary opencode-like SQLite databases for the OpencodeSessionStore
 * and tool tests.
 *
 * Every created file is registered under the global key
 * 'opencode_store_fixtures' so the test files' afterEach hooks can clean them
 * up with cleanup().
 */
class OpencodeStoreFixture
{
    /**
     * Create a temporary SQLite database with the opencode session, message and
     * part tables seeded with the given rows.
     *
     * Parts may reference a message row via message_id; rows without one are
     * left null so existing tests keep working unchanged.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     * @param  array<int, array<string, mixed>>  $parts
     * @param  array<int, array<string, mixed>>  $messages
     */
    public static function create(array $sessions = [], array $parts = [], array $messages = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'opencode_store_');

        $GLOBALS['opencode_store_fixtures'][] = $path;

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
        $pdo->exec(<<<'SQL'
            CREATE TABLE message (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                time_created INTEGER NOT NULL,
                time_updated INTEGER NOT NULL,
                data TEXT NOT NULL
            );
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE part (
                id TEXT PRIMARY KEY,
                message_id TEXT,
                session_id TEXT NOT NULL,
                time_created INTEGER NOT NULL,
                time_updated INTEGER NOT NULL,
                data TEXT NOT NULL
            );
            SQL);

        $sessionStatement = $pdo->prepare('INSERT INTO session (id, title, directory, parent_id, time_created, time_updated, time_archived) VALUES (?, ?, ?, ?, ?, ?, ?)');

        foreach ($sessions as $session) {
            $sessionStatement->execute([
                $session['id'],
                $session['title'],
                $session['directory'],
                $session['parent_id'] ?? null,
                $session['time_created'],
                $session['time_updated'],
                $session['time_archived'] ?? null,
            ]);
        }

        $messageStatement = $pdo->prepare('INSERT INTO message (id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?)');

        foreach ($messages as $message) {
            $messageStatement->execute([
                $message['id'],
                $message['session_id'],
                $message['time_created'],
                $message['time_updated'],
                $message['data'],
            ]);
        }

        $partStatement = $pdo->prepare('INSERT INTO part (id, message_id, session_id, time_created, time_updated, data) VALUES (?, ?, ?, ?, ?, ?)');

        foreach ($parts as $part) {
            $partStatement->execute([
                $part['id'],
                $part['message_id'] ?? null,
                $part['session_id'],
                $part['time_created'],
                $part['time_updated'],
                $part['data'],
            ]);
        }

        return $path;
    }

    /**
     * Remove every temporary database created by create().
     */
    public static function cleanup(): void
    {
        foreach ($GLOBALS['opencode_store_fixtures'] ?? [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $GLOBALS['opencode_store_fixtures'] = [];
    }
}
