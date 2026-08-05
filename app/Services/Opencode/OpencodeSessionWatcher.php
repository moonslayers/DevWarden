<?php

namespace App\Services\Opencode;

use App\Models\BotSetting;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeWorkflow;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Services\Opencode\Exceptions\OpencodeException;
use App\Services\Opencode\Exceptions\OpencodeProjectNotAllowed;
use Illuminate\Support\Facades\Log;

/**
 * Discovers every opencode session (including ones started outside the bot)
 * and notifies the owner's Telegram chat when one finishes, asks a question,
 * or fails. Invoked periodically from the monitor and never throws, so a
 * failing session cannot break the workflow monitoring flow.
 */
class OpencodeSessionWatcher
{
    /**
     * Max characters kept for the assistant summary in a notification.
     */
    private const SUMMARY_MAX_LENGTH = 2000;

    /**
     * A session whose Updated timestamp is newer than this many hours is
     * considered recently active and safe to bootstrap-notify on first sight.
     */
    private const RECENT_ACTIVITY_HOURS = 24;

    /**
     * Sessions observed as stopped are re-checked at most every this many
     * minutes, so the historical sessions do not trigger a heavy MCP
     * inspection (sessionInfo + opencode_check) on every tick.
     */
    private const STABLE_RECHECK_MINUTES = 5;

    public function __construct(
        private readonly OpencodeSessionManager $manager,
        private readonly OpencodeNotifier $notifier,
        private readonly OpencodeSessionParser $parser,
    ) {}

    /**
     * Check all opencode sessions and notify the owner about state transitions.
     */
    public function check(): void
    {
        $ownerId = BotSetting::singleton()->owner_user_id;

        if ($ownerId === null) {
            Log::debug('OpencodeSessionWatcher: no owner configured, skipping.');

            return;
        }

        $chatId = $this->resolveOwnerChatId($ownerId);

        if ($chatId === null) {
            Log::debug('OpencodeSessionWatcher: owner has no Telegram conversation, skipping.');

            return;
        }

        try {
            $sessions = $this->manager->listSessions();
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to list sessions.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($sessions === []) {
            return;
        }

        $excludedSessionIds = OpencodeWorkflow::query()
            ->whereNotNull('opencode_session_id')
            ->pluck('opencode_session_id')
            ->all();

        $permissionSessionIds = $this->pendingPermissionSessionIds();

        foreach ($sessions as $session) {
            if (in_array($session['id'], $excludedSessionIds, true)) {
                continue;
            }

            $this->inspectSession($session, $chatId, $permissionSessionIds);
        }
    }

    /**
     * Resolve the Telegram chat id to notify the owner on, preferring the most
     * recent conversation whose chat_id is inside the bot's allowed_user_ids.
     * When allowed_user_ids is empty or no owner conversation matches it, the
     * latest owner conversation is used as a fallback.
     */
    private function resolveOwnerChatId(int $ownerId): ?int
    {
        $allowedUserIds = array_map('intval', TelegramSetting::singleton()->allowed_user_ids ?? []);

        $query = TelegramChatConversation::query()
            ->where('user_id', $ownerId);

        if ($allowedUserIds !== []) {
            $chatId = (clone $query)
                ->whereIn('chat_id', $allowedUserIds)
                ->latest('id')
                ->value('chat_id');

            if ($chatId !== null) {
                return $chatId;
            }
        }

        return $query->latest('id')->value('chat_id');
    }

    /**
     * Apply the transition logic for a single session.
     *
     * The "Tasks:" line from opencode_check is the only reliable activity
     * signal for TUI sessions (the Status field and the "Done!" footer appear
     * even while tasks are in progress), so a session is classified as
     * "stopped" only when every task is completed and as "working" otherwise.
     *
     * @param  array{id: string, title: string, status: string}  $session
     * @param  list<string>  $permissionSessionIds
     */
    private function inspectSession(array $session, int $chatId, array $permissionSessionIds): void
    {
        $watch = OpencodeSessionWatch::firstOrCreate(
            ['session_id' => $session['id']],
            [
                'chat_id' => $chatId,
                'title' => $session['title'],
                'last_seen_status' => 'unknown',
                'checked_at' => now(),
            ],
        );

        // A freshly discovered session is only registered, never notified, so
        // historical sessions do not spam the owner on the first tick.
        if ($watch->wasRecentlyCreated) {
            return;
        }

        // A session already observed as stopped is only re-checked every few
        // minutes, so the historical sessions do not trigger a heavy MCP
        // inspection (sessionInfo + opencode_check) on every tick.
        if ($watch->last_seen_status === 'stopped'
            && $watch->checked_at !== null
            && $watch->checked_at->isAfter(now()->subMinutes(self::STABLE_RECHECK_MINUTES))) {
            return;
        }

        try {
            $info = $this->manager->sessionInfo($session['id']);
        } catch (OpencodeProjectNotAllowed $e) {
            Log::debug('OpencodeSessionWatcher: session directory outside allowed projects, skipping.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to fetch session info.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $directory = $info['directory'];
        $updatedAt = $info['updated_at'];

        try {
            $progress = $this->manager->sessionProgress($session['id'], (string) $directory);
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to fetch session progress.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($this->looksError($session, $progress['raw'])) {
            $this->notifyError($watch, $session, $chatId, (string) $directory);

            return;
        }

        if (! $progress['all_tasks_completed']) {
            $watch->forceFill([
                'last_seen_status' => 'working',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        $isFresh = $updatedAt !== null
            && $updatedAt->isAfter(now()->subHours(self::RECENT_ACTIVITY_HOURS));
        $justStopped = $watch->last_seen_status === 'working';
        $neverNotified = $watch->last_notified_event === null;
        $shouldNotify = $justStopped || ($neverNotified && $isFresh);

        if (! $shouldNotify) {
            $watch->forceFill([
                'last_seen_status' => 'stopped',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        $this->notifyFinished($watch, $session, $chatId, $permissionSessionIds, (string) $directory);
    }

    /**
     * Notify the owner that a session finished or is waiting on an answer.
     *
     * @param  array{id: string, title: string, status: string}  $session
     * @param  list<string>  $permissionSessionIds
     */
    private function notifyFinished(
        OpencodeSessionWatch $watch,
        array $session,
        int $chatId,
        array $permissionSessionIds,
        string $directory,
    ): void {
        try {
            $conversation = $this->manager->conversation($session['id'], $directory);
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to fetch session conversation.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $last = $this->parser->lastAssistantText($conversation);
        $summary = $this->parser->truncate($last, self::SUMMARY_MAX_LENGTH);
        $hasQuestion = $this->parser->hasQuestions($last)
            || in_array($session['id'], $permissionSessionIds, true);

        $event = $hasQuestion ? 'question' : 'finished';
        $title = $this->resolveTitle($session, $watch);

        $message = $hasQuestion
            ? "La sesión de opencode \"{$title}\" tiene preguntas.\n\n{$summary}\n\nResponde para continuar."
            : "La sesión de opencode \"{$title}\" terminó.\n\nProyecto: {$directory}\n\n{$summary}";

        $sent = $this->notifier->notify($chatId, $message);

        if (! $sent) {
            Log::warning('OpencodeSessionWatcher: notification failed, will retry next tick.', [
                'session_id' => $session['id'],
            ]);

            return;
        }

        $watch->forceFill([
            'last_seen_status' => 'stopped',
            'last_notified_event' => $event,
            'project_path' => $directory,
            'title' => $title,
            'notified_at' => now(),
            'checked_at' => now(),
        ])->save();
    }

    /**
     * Notify the owner that a session failed. Best-effort: the error signal
     * (a `Status: **error**` line, "Failed.", or the overview status) is not
     * live-verified, so an already-notified error is never re-notified.
     *
     * @param  array{id: string, title: string, status: string}  $session
     */
    private function notifyError(OpencodeSessionWatch $watch, array $session, int $chatId, string $directory): void
    {
        if ($watch->last_notified_event === 'error') {
            $watch->forceFill(['checked_at' => now()])->save();

            return;
        }

        $title = $this->resolveTitle($session, $watch);

        $message = "La sesión de opencode \"{$title}\" falló.\n\nProyecto: {$directory}";

        $sent = $this->notifier->notify($chatId, $message);

        if (! $sent) {
            Log::warning('OpencodeSessionWatcher: notification failed, will retry next tick.', [
                'session_id' => $session['id'],
            ]);

            return;
        }

        $watch->forceFill([
            'last_seen_status' => 'error',
            'last_notified_event' => 'error',
            'project_path' => $directory,
            'title' => $title,
            'notified_at' => now(),
            'checked_at' => now(),
        ])->save();
    }

    /**
     * Whether the session looks failed, from the overview status or the raw
     * opencode_check output.
     *
     * @param  array{id: string, title: string, status: string}  $session
     */
    private function looksError(array $session, string $raw): bool
    {
        return $session['status'] === 'error'
            || preg_match('/Status:\s*\*\*error\*\*/', $raw) === 1
            || str_contains($raw, 'Failed.');
    }

    /**
     * Prefer the overview title, falling back to the persisted one.
     *
     * @param  array{id: string, title: string, status: string}  $session
     */
    private function resolveTitle(array $session, OpencodeSessionWatch $watch): string
    {
        return $session['title'] !== '' ? $session['title'] : (string) $watch->title;
    }

    /**
     * Best-effort extraction of the session ids that have a pending permission.
     *
     * @return list<string>
     */
    private function pendingPermissionSessionIds(): array
    {
        try {
            $permissions = $this->manager->pendingPermissions();
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to list pending permissions.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $ids = [];

        foreach ($permissions as $permission) {
            if (isset($permission['sessionId'])) {
                $ids[] = $permission['sessionId'];
            }
        }

        return array_values(array_unique($ids));
    }
}
