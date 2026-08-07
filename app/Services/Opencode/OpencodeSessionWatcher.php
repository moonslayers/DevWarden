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
     * Max characters kept for an interactive question's text in a notification.
     */
    private const QUESTION_TEXT_MAX_LENGTH = 200;

    /**
     * Max characters kept for an answer option label in a notification.
     */
    private const OPTION_TEXT_MAX_LENGTH = 120;

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
     * A stopped session is only reported as finished after it has stayed idle
     * for this many minutes. opencode writes "thinking gaps" between tool calls
     * (step-finish -> step-start -> reasoning) with no running part, so a live
     * session can look stopped for tens of seconds; a genuine finish is
     * indistinguishable from a transient gap in the database, so idle time is
     * the only reliable discriminator before confirming "La sesión ... terminó".
     */
    private const FINISH_CONFIRM_MINUTES = 3;

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

    /**
     * The boot summary lists at most this many active sessions so the single
     * "active since boot" message stays compact.
     */
    private const MAX_BOOT_SUMMARY_SESSIONS = 10;

    /**
     * When computing the boot summary after a detected restart, sessions with
     * activity this far BEFORE the freshly reset watermark are still included.
     * Without the grace window a session updated a few seconds before the
     * restart (time_updated slightly older than the reset) is invisible on the
     * boot tick and the summary is skipped entirely.
     */
    private const BOOT_SUMMARY_GRACE_MINUTES = 5;

    /**
     * Minutes of history kept around the watermark when the main discovery runs.
     *
     * A watermark reset (restart detection) cuts every session whose time_updated
     * predates the reset. A session that asked a question right before the reset
     * then goes idle (stale time_updated) and would drop out of discovery, so its
     * question-turn notification never fires. The main loop therefore uses the
     * same grace window the boot summary applies, keeping recently-idle sessions
     * discoverable.
     */
    private const DISCOVERY_GRACE_MINUTES = 5;

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
            $sessions = $this->store->activeSessions($this->discoveryCutoff($since));
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to list active sessions.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $excludedSessionIds = OpencodeWorkflow::query()
            ->whereNotNull('opencode_session_id')
            ->pluck('opencode_session_id')
            ->all();

        $permissionSessionIds = $this->pendingPermissionSessionIds();

        $dismissedSessionIds = $this->dismissedSessionIds();

        // The boot summary is also evaluated when discovery returned an empty
        // list: the grace window may still surface sessions active just before
        // the restart, and an empty result now sends an immediate "no active
        // sessions" message so the owner always gets a boot notification.
        // Sessions waiting for the user's answer are excluded from the summary:
        // each one gets its own question-turn notification (with the actual
        // question) from the session loop below.
        $this->maybeSendBootSummary($sessions, $chatId, $since, $excludedSessionIds, $dismissedSessionIds);

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
     * The schedule:work boot anchor (schedule_booted_at) is the authoritative
     * restart signal: it is stamped the moment schedule:work actually starts,
     * so a restart of dev:full inside the ten-minute window — invisible to the
     * age heuristic below — is still detected on the first monitor tick. When
     * the anchor is newer than the current watermark the restart is re-armed
     * with the anchor as the new watermark, so a fast restart re-emits the boot
     * summary immediately instead of waiting for the old watermark to age out.
     *
     * Once the anchor has been adopted (the watermark equals the anchor), a
     * later tick must NOT re-arm anything: the anchor is no longer "newer" and
     * the service having run for more than ten minutes is not a restart. The
     * age heuristic below is therefore exclusive to installs without the anchor.
     *
     * Without the anchor the watermark resets to "now" on the first run (null
     * watermark) and after a restart (more than ten minutes since the last
     * tick), so only sessions with activity since the service started are ever
     * discovered. The watermark stays fixed between restarts so idle sessions
     * waiting for input remain discoverable.
     */
    private function resolveWatchSince(): ?int
    {
        $settings = OpencodeSetting::singleton();

        $since = $settings->session_watch_since;

        $scheduleBootedAt = $settings->schedule_booted_at;

        if ($scheduleBootedAt !== null) {
            // A restart detected via the anchor also re-arms the boot summary
            // marker, so this boot emits exactly one "sessions active since
            // startup" report.
            if ($since === null || $scheduleBootedAt->isAfter($since)) {
                $settings->session_watch_boot_reported_at = null;
                $settings->session_watch_since = $scheduleBootedAt;
                $settings->save();

                $since = $settings->session_watch_since;
            }

            // An anchor that has already been adopted (watermark >= anchor) is
            // NOT a restart, even once the watermark is older than the restart
            // window: return the current watermark untouched so the boot summary
            // marker is never re-armed mid-service. The >10-min age heuristic
            // below must not apply to installs that have an anchor.
            return $since?->getTimestampMs();
        }

        // Fallback for installs without the boot anchor: a watermark older
        // than the monitor's overlap window still detects the restart.
        if ($since === null
            || $since->diffInMinutes(now()) > self::WATCH_WATERMARK_RESTART_MINUTES) {
            $settings->session_watch_boot_reported_at = null;
            $settings->session_watch_since = now();
            $settings->save();

            $since = $settings->session_watch_since;
        }

        return $since?->getTimestampMs();
    }

    /**
     * Discovery cutoff for the current tick: the watermark minus a grace window.
     *
     * The watermark resets to now() when a restart is detected, which would cut
     * any session that went idle just before the reset (a live question turn
     * keeps a stale time_updated while it waits for input). Pushing the cutoff a
     * few minutes into the past keeps those sessions discoverable so their
     * question-turn notification still fires. A null watermark (degraded state)
     * falls back to no cutoff, exactly like the pre-grace behavior.
     */
    private function discoveryCutoff(?int $since): ?int
    {
        if ($since === null) {
            return null;
        }

        return $since - self::DISCOVERY_GRACE_MINUTES * 60 * 1000;
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
        // historical sessions do not spam the owner on the first tick. The one
        // exception is a session already waiting on a live 'question' turn:
        // inspecting it on the discovery tick removes a full tick of latency
        // without breaking the anti-spam contract (see inspectFreshSession).
        if ($watch->wasRecentlyCreated) {
            $this->inspectFreshSession($watch, $session, $chatId);

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
            $isQuestionTurn = $state['last_turn_tool'] === 'question';

            // A live running 'question' tool means the session is waiting for
            // the user's input — a notifiable milestone. It is reported once
            // per question turn: the marker is cleared again as soon as the
            // session works on anything else, so the next question turn is
            // notified instead of the already-reported one. The boot summary
            // never lists waiting sessions, so this message is always sent.
            if ($isQuestionTurn && $watch->last_notified_event !== 'question') {
                $this->notifyQuestionTurn($watch, $session, $chatId, $directory);

                return;
            }

            $watch->forceFill([
                'last_seen_status' => 'working',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
                'last_notified_event' => $isQuestionTurn ? $watch->last_notified_event : null,
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

        // A stop is only reported as finished once the session has stayed idle
        // for the confirmation window. opencode writes "thinking gaps" between
        // tool calls (step-finish -> step-start -> reasoning) with no running
        // part, so a live session can look stopped for tens of seconds, and a
        // genuine finish is indistinguishable from a transient gap in the
        // database — the only reliable discriminator is idle time. The first
        // observation turns the watch into a 'stopping' candidate without
        // notifying; the candidate is confirmed only after the window elapses,
        // and is cleared silently by the working branch as soon as the session
        // runs again.
        //
        // The confirm clock uses the discovery row's effective freshness
        // (MAX(session.time_updated, MAX(part.time_created))), which equals the
        // session's last real activity once stopped — unlike the raw $updatedAt
        // above, which feeds only the freshness gate.
        $effectiveUpdatedAt = $this->updatedAtFromEpochMs($session['time_updated'] ?? null);

        // A session whose effective last-activity cannot be resolved must not
        // become a permanent 'stopping' candidate (never confirming, never
        // falling to 'stopped'), so it takes the register-only outcome exactly
        // like a fresh/unknown/error watch.
        if ($effectiveUpdatedAt === null) {
            $watch->forceFill([
                'last_seen_status' => 'stopped',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        $idleMinutes = $effectiveUpdatedAt->diffInMinutes(now());
        $confirmFinished = $idleMinutes >= self::FINISH_CONFIRM_MINUTES;

        if (($watch->last_seen_status === 'working' || $watch->last_seen_status === 'stopping')
            && $confirmFinished) {
            $this->notifyFinished($watch, $session, $chatId, $permissionSessionIds, $directory);

            return;
        }

        if ($watch->last_seen_status === 'working' || $watch->last_seen_status === 'stopping') {
            // 'working': first observation of the stop, promote the watch to a
            // 'stopping' candidate so the next tick can either confirm the
            // finish or clear it on a resume. 'stopping': still inside the
            // window, keep the candidate re-checked every tick — the
            // STABLE_RECHECK_MINUTES guard only matches confirmed 'stopped'
            // watches, never candidates.
            $watch->forceFill([
                'last_seen_status' => 'stopping',
                'title' => $this->resolveTitle($session, $watch),
                'project_path' => $directory,
                'checked_at' => now(),
            ])->save();

            return;
        }

        // 'unknown' (fresh watch), 'error' or 'stopped': register-only, so
        // history found already stopped on the first tick never notifies.
        $watch->forceFill([
            'last_seen_status' => 'stopped',
            'title' => $this->resolveTitle($session, $watch),
            'project_path' => $directory,
            'checked_at' => now(),
        ])->save();
    }

    /**
     * Inspect a freshly discovered session, notifying only a live question turn.
     *
     * A newly registered watch is born 'unknown', so the working -> stopped
     * finish and the terminal-error branches can never fire on the first
     * sighting (they need a previously observed 'working' status or an error
     * part on a non-fresh session), and a session without a live question turn
     * is left register-only exactly like the old early return. The exception
     * is a session already paused on a live 'question' tool: its
     * last_notified_event is still null (never notified), so reporting the
     * turn on the discovery tick neither spams history nor re-notifies, and
     * the owner no longer waits a full extra tick for a turn that already
     * exists. Sub-agent sessions (parent_id set) are never inspected here.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     */
    private function inspectFreshSession(OpencodeSessionWatch $watch, array $session, int $chatId): void
    {
        if (($session['parent_id'] ?? null) !== null) {
            return;
        }

        try {
            $state = $this->store->sessionState($session['id']);
        } catch (Throwable $e) {
            Log::warning('OpencodeSessionWatcher: failed to resolve session state for a freshly discovered session.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $state['has_running_part'] || $state['last_turn_tool'] !== 'question') {
            return;
        }

        $directory = $state['directory'] ?? $session['directory'];

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

        $this->notifyQuestionTurn($watch, $session, $chatId, $directory);
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
        $hasPendingPermission = in_array($session['id'], $permissionSessionIds, true);

        $event = $hasPendingPermission ? 'question' : 'finished';
        $title = $this->resolveTitle($session, $watch);

        if ($hasPendingPermission) {
            $message = $this->buildQuestionNotification(
                $title,
                $session['id'],
                $summary,
                $this->resolveQuestionOptions($session['id']),
            );

            $sent = $this->notifier->notify($chatId, $message);
        } else {
            $message = "La sesión de opencode \"{$title}\" terminó.\n\nProyecto: {$directory}\n\n{$summary}";

            $sent = $this->notifier->notify($chatId, $message);
        }

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
     * Notify the owner that a live running 'question' turn is waiting for input.
     *
     * The session is still alive (has a running part) but paused on a question
     * the user must answer. The watch keeps last_seen_status 'working' so a
     * later working -> stopped transition still reports the finish; the
     * last_notified_event marker prevents a second notification for the same
     * turn, and is cleared by inspectSession as soon as the session works on
     * anything else.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     */
    private function notifyQuestionTurn(
        OpencodeSessionWatch $watch,
        array $session,
        int $chatId,
        string $directory,
    ): void {
        try {
            $conversation = $this->manager->conversation($session['id'], $directory);
        } catch (OpencodeException $e) {
            Log::warning('OpencodeSessionWatcher: failed to fetch session conversation for the question turn.', [
                'session_id' => $session['id'],
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $last = $this->parser->lastAssistantText($conversation);
        $summary = $this->parser->truncate($last, self::SUMMARY_MAX_LENGTH);
        $title = $this->resolveTitle($session, $watch);

        $message = $this->buildQuestionNotification(
            $title,
            $session['id'],
            $summary,
            $this->resolveQuestionOptions($session['id']),
        );

        $sent = $this->notifier->notify($chatId, $message);

        if (! $sent) {
            Log::warning('OpencodeSessionWatcher: question notification failed, will retry next tick.', [
                'session_id' => $session['id'],
            ]);

            return;
        }

        $this->persistQuestionTurnReported($watch, $session, $directory);
    }

    /**
     * Persist the watch state a successfully reported question turn leaves.
     *
     * The session keeps last_seen_status 'working' so a later working ->
     * stopped transition still reports the finish; last_notified_event =
     * 'question' prevents a second notification for the same turn, and is
     * cleared by inspectSession as soon as the session works on anything else.
     *
     * @param  array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}  $session
     */
    private function persistQuestionTurnReported(OpencodeSessionWatch $watch, array $session, string $directory): void
    {
        $watch->forceFill([
            'last_seen_status' => 'working',
            'last_notified_event' => 'question',
            'project_path' => $directory,
            'title' => $this->resolveTitle($session, $watch),
            'notified_at' => now(),
            'checked_at' => now(),
        ])->save();
    }

    /**
     * Resolve the session's interactive answer options, best-effort.
     *
     * The store never throws, but the watcher's never-throws contract still
     * guards the call: a failure degrades to no options, so the notification
     * falls back to the plain text message.
     *
     * @return list<array{question: string, options: list<array{label: string, description: ?string}>}>
     */
    private function resolveQuestionOptions(string $sessionId): array
    {
        try {
            return $this->store->questionOptions($sessionId);
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to resolve question options.', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Build the "session has questions" notification as plain text.
     *
     * When the store exposes interactive answer options the message lists every
     * question with its lettered options and the session id, so the owner can
     * answer by chat. Without options the message degrades to the session
     * summary alone.
     *
     * @param  list<array{question: string, options: list<array{label: string, description: ?string}>}>  $questions
     */
    private function buildQuestionNotification(string $title, string $sessionId, string $summary, array $questions): string
    {
        if ($questions === []) {
            return "La sesión de opencode \"{$title}\" tiene preguntas.\n\n{$summary}\n\nResponde para continuar.";
        }

        $lines = [
            "La sesión de opencode \"{$title}\" tiene preguntas.",
            '',
            "Sesión: {$sessionId}",
            '',
        ];

        foreach ($questions as $questionIndex => $question) {
            $lines[] = 'Pregunta '.($questionIndex + 1).': '
                .$this->parser->truncate($question['question'], self::QUESTION_TEXT_MAX_LENGTH);

            foreach ($question['options'] as $optionIndex => $option) {
                $lines[] = '('.$this->optionLetter($optionIndex).') '
                    .$this->parser->truncate($option['label'], self::OPTION_TEXT_MAX_LENGTH);
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Letter an option index for display (a, b, c, ...), falling back to the
     * raw number once the Latin alphabet is exhausted.
     */
    private function optionLetter(int $index): string
    {
        return $index < 26 ? chr(ord('a') + $index) : (string) $index;
    }

    /**
     * Send the single "sessions active since startup" summary, once per boot.
     *
     * The boot summary is armed when resolveWatchSince detects a restart and
     * persisted only once it has actually been sent, so a successful boot
     * reports exactly once. A failed notify leaves the marker unset and the
     * next tick retries. When no relevant session exists, an immediate
     * "system started with no active sessions" message is sent in the same
     * tick so the owner always receives at least one boot notification, and
     * the marker is sealed only after that send succeeds — a failed send is
     * retried on the next tick exactly like the non-empty branch.
     *
     * @param  list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>  $sessions
     * @param  list<string>  $excludedSessionIds
     * @param  list<string>  $dismissedSessionIds
     */
    private function maybeSendBootSummary(
        array $sessions,
        int $chatId,
        ?int $since,
        array $excludedSessionIds,
        array $dismissedSessionIds,
    ): void {
        try {
            $settings = OpencodeSetting::singleton();
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to read the boot summary marker, skipping.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($settings->session_watch_boot_reported_at !== null) {
            return;
        }

        $relevant = $this->bootSummarySessions(
            $this->bootSummaryCandidateSessions($sessions, $since),
            $excludedSessionIds,
            $dismissedSessionIds,
        );

        $message = $relevant === []
            ? $this->formatBootSummaryEmpty()
            : $this->formatBootSummary($relevant);

        $sent = $this->notifier->notify($chatId, $message);

        if (! $sent) {
            Log::warning('OpencodeSessionWatcher: boot summary notification failed, will retry next tick.', []);

            return;
        }

        $this->markBootSummaryReported($settings);
    }

    /**
     * Build the session set the boot summary evaluates over.
     *
     * During the boot window the summary must include sessions whose last
     * activity fell shortly BEFORE the freshly reset watermark (a few seconds
     * of race between the restart and the reset), so discovery is re-run with
     * a grace-window cutoff. On failure the discovery set is used unchanged.
     *
     * @param  list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>  $sessions
     * @return list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>
     */
    private function bootSummaryCandidateSessions(array $sessions, ?int $since): array
    {
        if ($since === null) {
            return $sessions;
        }

        $graceSince = $since - self::BOOT_SUMMARY_GRACE_MINUTES * 60 * 1000;

        try {
            return $this->store->activeSessions($graceSince);
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to extend the boot summary to the grace window, falling back to the discovery set.', [
                'error' => $e->getMessage(),
            ]);

            return $sessions;
        }
    }

    /**
     * Collect the sessions relevant to the boot summary: not workflow-owned,
     * not dismissed, not sub-agents and currently live (a running part).
     *
     * Sessions waiting for the user's answer are intentionally EXCLUDED: each
     * gets its own question-turn notification carrying the actual question, so
     * listing them here would both duplicate that message and hide its content.
     *
     * @param  list<array{id: string, title: ?string, directory: ?string, time_updated: ?int, parent_id: ?string}>  $sessions
     * @param  list<string>  $excludedSessionIds
     * @param  list<string>  $dismissedSessionIds
     * @return list<array{id: string, title: string, directory: string}>
     */
    private function bootSummarySessions(array $sessions, array $excludedSessionIds, array $dismissedSessionIds): array
    {
        $relevant = [];

        foreach ($sessions as $session) {
            if (in_array($session['id'], $excludedSessionIds, true)
                || in_array($session['id'], $dismissedSessionIds, true)
                || ($session['parent_id'] ?? null) !== null) {
                continue;
            }

            try {
                $state = $this->store->sessionState($session['id']);
            } catch (Throwable $e) {
                Log::debug('OpencodeSessionWatcher: failed to resolve session state for the boot summary.', [
                    'session_id' => $session['id'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $state['has_running_part'] || $state['last_turn_tool'] === 'question') {
                continue;
            }

            $title = $session['title'];
            $directory = $state['directory'] ?? $session['directory'];

            $relevant[] = [
                'id' => $session['id'],
                'title' => ($title !== null && $title !== '') ? $title : $session['id'],
                'directory' => $directory ?? '',
            ];

            if (count($relevant) >= self::MAX_BOOT_SUMMARY_SESSIONS) {
                break;
            }
        }

        return $relevant;
    }

    /**
     * @param  list<array{id: string, title: string, directory: string}>  $sessions
     */
    private function formatBootSummary(array $sessions): string
    {
        $lines = array_map(
            static fn (array $session): string => '- '.$session['title'].' ('.$session['directory'].') — trabajando',
            $sessions,
        );

        return "Sesiones activas desde el inicio del servidor:\n".implode("\n", $lines);
    }

    /**
     * Message sent when the boot summary finds no relevant session, so the
     * owner always receives at least one notification per detected restart.
     */
    private function formatBootSummaryEmpty(): string
    {
        return 'Sistema DevWarden iniciado. No hay sesiones de opencode activas.';
    }

    /**
     * Persist the "boot summary already reported" marker so later ticks do not
     * emit it again. Best-effort: a failure degrades to not marking, which
     * only means the summary is retried on the next tick.
     */
    private function markBootSummaryReported(?OpencodeSetting $settings = null): void
    {
        try {
            $settings ??= OpencodeSetting::singleton();

            $settings->session_watch_boot_reported_at = now();
            $settings->save();
        } catch (Throwable $e) {
            Log::debug('OpencodeSessionWatcher: failed to persist the boot summary marker.', [
                'error' => $e->getMessage(),
            ]);
        }
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
