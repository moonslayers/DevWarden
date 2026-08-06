<?php

namespace App\Services\Opencode;

use App\Models\OpencodeSetting;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only store over the local opencode SQLite database.
 *
 * Session activity is detected by querying the opencode database directly
 * instead of parsing MCP text output. Every connection is opened in read-only
 * mode (sqlite URI mode=ro plus PRAGMA query_only) so the store can never
 * mutate opencode's own database, and every method degrades to an empty result
 * with a Log::debug line when the database is missing or unreadable.
 */
class OpencodeSessionStore
{
    /**
     * Per-user default database path, relative to the HOME directory, used when
     * no data_db_path is configured in the opencode settings.
     */
    private const DEFAULT_DATA_DB_PATH = '/.local/share/opencode/opencode.db';

    public function __construct(private readonly ?string $dbPath = null) {}

    /**
     * List every non-archived session, most recently updated first.
     *
     * When a since watermark is provided, only sessions with time_updated at or
     * after it are returned, so sessions that died before the watermark never
     * reach the watcher. Without a watermark every non-archived session is
     * listed, regardless of when it was last updated.
     *
     * @return list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>
     */
    public function activeSessions(?int $sinceEpochMs = null): array
    {
        try {
            $sql = <<<'SQL'
                SELECT id, title, directory, time_updated, parent_id
                FROM session
                WHERE time_archived IS NULL
                SQL;

            $parameters = [];

            if ($sinceEpochMs !== null) {
                $sql .= ' AND time_updated >= :since';
                $parameters['since'] = $sinceEpochMs;
            }

            $sql .= ' ORDER BY time_updated DESC';

            $statement = $this->pdo()->prepare($sql);
            $statement->execute($parameters);

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return array_map(
                static fn (array $row): array => [
                    'id' => (string) $row['id'],
                    'title' => $row['title'] !== null ? (string) $row['title'] : null,
                    'directory' => $row['directory'] !== null ? (string) $row['directory'] : null,
                    'time_updated' => $row['time_updated'] !== null ? (int) $row['time_updated'] : null,
                    'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
                ],
                $rows,
            );
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionStore: failed to list active sessions.', [
                'db_path' => $this->dbPath(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Search every session — active, dismissed and archived — by title or
     * directory, most recently updated first.
     *
     * Unlike activeSessions() there is no archive filter and no watermark: old
     * and archived sessions stay discoverable so the bot can find and
     * reactivate a session the user mentions by name or project path. The query
     * is matched as a substring (LIKE) of either column.
     *
     * @return list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string, time_archived: ?int}>
     */
    public function searchSessions(string $query, int $limit = 10): array
    {
        try {
            $limit = max(1, min(25, $limit));

            $pattern = '%'.self::escapeLike($query).'%';

            $sql = <<<'SQL'
                SELECT id, title, directory, time_updated, parent_id, time_archived
                FROM session
                WHERE title LIKE :title_query ESCAPE '\'
                   OR directory LIKE :directory_query ESCAPE '\'
                ORDER BY time_updated DESC
                SQL;

            $sql .= ' LIMIT '.$limit;

            $statement = $this->pdo()->prepare($sql);
            $statement->execute([
                'title_query' => $pattern,
                'directory_query' => $pattern,
            ]);

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return array_values(array_map(
                static fn (array $row): array => [
                    'id' => (string) $row['id'],
                    'title' => $row['title'] !== null ? (string) $row['title'] : null,
                    'directory' => $row['directory'] !== null ? (string) $row['directory'] : null,
                    'time_updated' => $row['time_updated'] !== null ? (int) $row['time_updated'] : null,
                    'parent_id' => $row['parent_id'] !== null ? (string) $row['parent_id'] : null,
                    'time_archived' => $row['time_archived'] !== null ? (int) $row['time_archived'] : null,
                ],
                $rows,
            ));
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionStore: failed to search sessions.', [
                'query' => $query,
                'limit' => $limit,
                'db_path' => $this->dbPath(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve the metadata and part status flags for a single session.
     *
     * @return array{title: ?string, directory: ?string, time_updated: ?int, has_running_part: bool, has_error_part: bool, has_any_part: bool}
     */
    public function sessionState(string $sessionId): array
    {
        try {
            $statement = $this->pdo()->prepare(<<<'SQL'
                SELECT
                    session.title,
                    session.directory,
                    session.time_updated,
                    EXISTS(SELECT 1 FROM part WHERE part.session_id = session.id) AS has_any_part,
                    EXISTS(
                        SELECT 1 FROM part
                        WHERE part.session_id = session.id
                          AND json_extract(part.data, '$.state.status') = 'running'
                    ) AS has_running_part,
                    EXISTS(
                        SELECT 1 FROM part
                        WHERE part.session_id = session.id
                          AND json_extract(part.data, '$.type') = 'tool'
                          AND json_extract(part.data, '$.state.status') = 'error'
                    ) AS has_error_part
                FROM session
                WHERE session.id = ?
                SQL);
            $statement->execute([$sessionId]);

            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return $this->emptyState();
            }

            return [
                'title' => $row['title'] !== null ? (string) $row['title'] : null,
                'directory' => $row['directory'] !== null ? (string) $row['directory'] : null,
                'time_updated' => $row['time_updated'] !== null ? (int) $row['time_updated'] : null,
                'has_running_part' => (bool) $row['has_running_part'],
                'has_error_part' => (bool) $row['has_error_part'],
                'has_any_part' => (bool) $row['has_any_part'],
            ];
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionStore: failed to resolve session state.', [
                'session_id' => $sessionId,
                'db_path' => $this->dbPath(),
                'error' => $e->getMessage(),
            ]);

            return $this->emptyState();
        }
    }

    /**
     * Error markers that describe a transient, non-terminal tool failure the
     * agent recovers from, so a tool error carrying any of them is never
     * treated as a session failure.
     *
     * @var list<string>
     */
    private const NON_TERMINAL_ERROR_MARKERS = [
        'tool execution aborted',
        'task cancelled',
        'dismissed this question',
        'prevents you from using',
    ];

    /**
     * Whether the session ended on a genuine terminal tool failure.
     *
     * True only when the most recent part is a tool part in error state whose
     * error text does not match a non-terminal marker (aborted, rule-blocked,
     * user-dismissed). A transient error the agent kept working through is
     * never terminal because a newer part becomes the most recent one.
     */
    public function hasTerminalError(string $sessionId): bool
    {
        try {
            $sql = <<<'SQL'
                SELECT COUNT(*) FROM (
                    SELECT data
                    FROM part
                    WHERE session_id = :session_id
                    ORDER BY time_created DESC, rowid DESC
                    LIMIT 1
                ) AS last_part
                WHERE json_extract(last_part.data, '$.type') = 'tool'
                  AND json_extract(last_part.data, '$.state.status') = 'error'
                SQL;

            $parameters = ['session_id' => $sessionId];

            foreach (self::NON_TERMINAL_ERROR_MARKERS as $index => $marker) {
                $sql .= " AND lower(coalesce(json_extract(last_part.data, '\$.state.error'), '')) NOT LIKE :marker{$index}";
                $parameters["marker{$index}"] = '%'.$marker.'%';
            }

            $statement = $this->pdo()->prepare($sql);
            $statement->execute($parameters);

            return (bool) $statement->fetchColumn();
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionStore: failed to resolve terminal error signal.', [
                'session_id' => $sessionId,
                'db_path' => $this->dbPath(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resolve the opencode database path.
     *
     * The constructor override wins, then the configured data_db_path setting,
     * then the per-user default under the HOME directory.
     */
    public function dbPath(): string
    {
        if ($this->dbPath !== null) {
            return $this->dbPath;
        }

        $configured = OpencodeSetting::singleton()->data_db_path;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');

        return $home === '' ? '' : $home.self::DEFAULT_DATA_DB_PATH;
    }

    /**
     * @return array{title: null, directory: null, time_updated: null, has_running_part: false, has_error_part: false, has_any_part: false}
     */
    protected function emptyState(): array
    {
        return [
            'title' => null,
            'directory' => null,
            'time_updated' => null,
            'has_running_part' => false,
            'has_error_part' => false,
            'has_any_part' => false,
        ];
    }

    /**
     * Escape LIKE wildcards in a user query so they match literal characters.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Open a fresh read-only connection to the opencode database.
     */
    protected function pdo(): PDO
    {
        $path = $this->dbPath();

        if (! is_file($path)) {
            throw new RuntimeException("Opencode database not found at [{$path}].");
        }

        $uriPath = str_replace('%2F', '/', rawurlencode($path));

        $pdo = new PDO("sqlite:file:{$uriPath}?mode=ro");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA query_only = ON');

        return $pdo;
    }
}
