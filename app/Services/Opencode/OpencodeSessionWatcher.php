<?php

namespace App\Services\Opencode;

use App\Models\BotSetting;
use App\Models\OpencodeSessionDismissal;
use App\Models\OpencodeSessionWatch;
use App\Models\OpencodeSetting;
use App\Models\OpencodeWorkflow;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use App\Services\Opencode\Exceptions\OpencodeException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Discovers every opencode session (including ones started outside the bot)
 * and notifies the owner's Telegram chat when one finishes, asks a question,
 * or fails. Invoked periodically from the monitor and never throws, so a
 * failing session cannot break the workflow monitoring flow.
 *
 * Session activity is read from the local opencode SQLite database via
 * OpencodeSessionStore (never from the MCP text output), while the MCP client
 * is only used for the conversation summary and pending-permission detection.
 */
class OpencodeSessionWatcher
{
    /**
     * Max characters kept for the assistant summary in a notification.
     */
    private const SUMMARY_MAX_LENGTH = 2000;

    /**
     * A session whose Updated timestamp is newer than this many hours is
     * considered recently active, used to gate the error notification signal.
     */
    private const RECENT_ACTIVITY_HOURS = 24;

    /**
     * Sessions observed as stopped are re-checked at most every this many
     * minutes, so the historical sessions do not trigger a store lookup or an
     * MCP inspection on every tick.
     */
    private const STABLE_RECHECK_MINUTES = 5;

    /**
     * A session whose error was already notified within this many hours is not
     * notified again, regardless of later state transitions.
     */
    private const ERROR_NOTIFY_COOLDOWN_HOURS = 24;

    /**
     * The session-watch watermark is reset to "now" when the watcher has not
     * ticked for more than this many minutes, which detects a service restart
     * (the monitor runs every minute with a ten-minute overlap lock). The
     * watermark does not advance on every tick, so an idle session waiting for
     * input keeps a stale time_updated and stays discoverable.
     */
    private const WATCH_WATERMARK_RESTART_MINUTES = 10;

    public function __construct(
        private readonly OpencodeSessionStore $store,
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
            $since = $this->resolveWatchSince();
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to resolve the session watch watermark, falling back to no cutoff.', [
                'error' => $e->getMessage(),
            ]);

            $since = null;
        }

        try {
            $sessions = $this->store->activeSessions($since);
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to list active sessions.', [
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

        $dismissedSessionIds = $this->dismissedSessionIds();

        foreach ($sessions as $session) {
            if (in_array($session['id'], $excludedSessionIds, true)) {
                continue;
            }

            if (in_array($session['id'], $dismissedSessionIds, true)) {
                Log::debug('OpencodeSessionWatcher: skipping dismissed session.', [
                    'session_id' => $session['id'],
                ]);

                continue;
            }

            $this->inspectSession($session, $chatId, $permissionSessionIds);
        }
    }

    /**
     * Load the session ids the owner explicitly marked as done, so dismissed
     * sessions are never registered, inspected or notified again.
     *
     * @return list<string>
     */
    private function dismissedSessionIds(): array
    {
        try {
            return array_values(
                OpencodeSessionDismissal::query()
                    ->pluck('session_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all(),
            );
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to load dismissed session ids.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve the session-watch watermark in epoch milliseconds.
     *
     * On the first run (null watermark) and after a restart (more than ten
     * minutes since the last tick) the watermark is reset to "now", so only
     * sessions with activity since the service started are ever discovered.
     * The watermark stays fixed between restarts so idle sessions waiting for
     * input remain discoverable.
     */
    private function resolveWatchSince(): ?int
    {
        $settings = OpencodeSetting::singleton();

        $since = $settings->session_watch_since;

        if ($since === null
            || $since->diffInMinutes(now()) > self::WATCH_WATERMARK_RESTART_MINUTES) {
            $settings->session_watch_since = now();
            $settings->save();

            $since = $settings->session_watch_since;
        }

        return $since?->getTimestampMs();
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
     * Session state comes from the opencode database (OpencodeSessionStore):
     * a session is "working" while any part is running, "stopped" once it is
     * idle, and failing when the most recent part is a genuine terminal tool
     * error. Sessions without any part are registered only and never notified.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     * @param  list<string>  $permissionSessionIds
     */
    private function inspectSession(array $session, int $chatId, array $permissionSessionIds): void
    {
        $isSubagent = ($session['parent_id'] ?? null) !== null;

        $watch = OpencodeSessionWatch::firstOrCreate(
            ['session_id' => $session['id']],
            [
                'chat_id' => $chatId,
                'title' => $session['title'],
                'last_seen_status' => 'unknown',
                'checked_at' => now(),
                'is_subagent' => $isSubagent,
            ],
        );

        // A freshly discovered session is only registered, never notified, so
        // historical sessions do not spam the owner on the first tick.
        if ($watch->wasRecentlyCreated) {
            return;
        }

        // A session already observed as stopped is only re-checked every few
        // minutes, so historical sessions do not trigger a store lookup or an
        // MCP inspection on every tick.
        if ($watch->last_seen_status === 'stopped'
            && $watch->checked_at !== null
            && $watch->checked_at->isAfter(now()->subMinutes(self::STABLE_RECHECK_MINUTES))) {
            return;
        }

        try {
            $state = $this->store->sessionState($session['id']);
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to resolve session state.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $directory = $state['directory'] ?? $session['directory'];

        // Without a directory the session cannot be validated against the
        // project whitelist nor notified correctly, so it is skipped safely.
        if ($directory === null) {
            Log::debug('OpencodeSessionWatcher: session has no directory, skipping.', [
                'session_id' => $session['id'],
            ]);

            return;
        }

        try {
            $allowed = $this->manager->isAllowedProject($directory);
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to validate session directory, skipping.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $allowed) {
            Log::debug('OpencodeSessionWatcher: session directory outside allowed projects, skipping.', [
                'session_id' => $session['id'],
                'directory' => $directory,
            ]);

            return;
        }

        // Sub-agent sessions are tracked (for stats) but never notified, so
        // their transitions and failures never reach the owner's chat.
        if ($isSubagent) {
            $this->trackSubagent($watch, $session, $directory, $state);

            return;
        }

        $updatedAt = $this->updatedAtFromEpochMs($state['time_updated']);
        $isFresh = $updatedAt !== null
            && $updatedAt->isAfter(now()->subHours(self::RECENT_ACTIVITY_HOURS));

        // A session only fails when the error is its most recent part, the
        // agent is not still working through it, and the error is not a
        // transient abort/rule-block the agent recovers from. The cheap
        // has_error_part flag gates the extra terminal-error query.
        try {
            $hasTerminalError = $state['has_error_part']
                && $this->store->hasTerminalError($session['id']);
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to resolve terminal error signal.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($hasTerminalError
            && ! $state['has_running_part']
            && ($isFresh || $watch->last_seen_status === 'working')) {
            $this->notifyError($watch, $session, $chatId, $directory);

            return;
        }

        if ($state['has_running_part']) {
            $watch->forceFill([
                'last_seen_status' => 'working',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        if (! $state['has_any_part']) {
            $attributes = ['checked_at' => now()];

            if ($watch->last_seen_status === 'stopped') {
                $attributes['last_seen_status'] = 'stopped';
            }

            $watch->forceFill($attributes)->save();

            return;
        }

        // Only a live working -> stopped transition notifies. Sessions found
        // already stopped on the first tick (history) never notify, so the
        // store's current-day cutoff alone cannot produce a notification.
        $shouldNotify = $watch->last_seen_status === 'working';

        if (! $shouldNotify) {
            $watch->forceFill([
                'last_seen_status' => 'stopped',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        $this->notifyFinished($watch, $session, $chatId, $permissionSessionIds, $directory);
    }

    /**
     * Track a sub-agent session's watch row without ever notifying the owner.
     *
     * The row is kept consistent with the session classification (working while
     * a part is running, stopped otherwise) so future stats can rely on it, but
     * finished/error/question notifications are never sent for sub-agents.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     * @param  array{title: ?string, directory: ?string, time_updated: ?int, has_running_part: bool, has_error_part: bool, has_any_part: bool}  $state
     */
    private function trackSubagent(
        OpencodeSessionWatch $watch,
        array $session,
        string $directory,
        array $state,
    ): void {
        Log::debug('OpencodeSessionWatcher: tracking sub-agent session without notifying.', [
            'session_id' => $session['id'],
            'parent_id' => $session['parent_id'],
        ]);

        $watch->forceFill([
            'is_subagent' => true,
            'last_seen_status' => $state['has_running_part'] ? 'working' : 'stopped',
            'title' => $this->resolveTitle($session, $watch),
            'project_path' => $directory,
            'checked_at' => now(),
        ])->save();
    }

    /**
     * Notify the owner that a session finished or is waiting on an answer.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
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
     * comes from the store (a terminal tool error as the most recent part), so
     * a session whose error was already notified within the cooldown window is
     * never re-notified, regardless of later state transitions.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     */
    private function notifyError(OpencodeSessionWatch $watch, array $session, int $chatId, string $directory): void
    {
        if ($watch->notified_at !== null
            && $watch->notified_at->isAfter(now()->subHours(self::ERROR_NOTIFY_COOLDOWN_HOURS))) {
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
     * Prefer the store title, falling back to the persisted one.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     */
    private function resolveTitle(array $session, OpencodeSessionWatch $watch): string
    {
        return $session['title'] !== null && $session['title'] !== ''
            ? $session['title']
            : (string) $watch->title;
    }

    /**
     * Convert the opencode epoch-milliseconds timestamp into a Carbon instance.
     */
    private function updatedAtFromEpochMs(?int $timeUpdated): ?Carbon
    {
        if ($timeUpdated === null) {
            return null;
        }

        try {
            return Carbon::createFromTimestampMs($timeUpdated);
        } catch (Throwable) {
            return null;
        }
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
