<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\BotSetting;
use App\Models\TelegramChatConversation;
use App\Models\TelegramSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class DashboardController extends Controller
{
    /**
     * Show the dashboard.
     */
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'health' => $this->healthProps(),
            'activity' => $this->activityProps(),
            'usage' => $this->usageProps(),
        ]);
    }

    /**
     * Group a collection of items into daily series for the last N days ending today.
     *
     * @template T of object
     *
     * @param  Collection<int, T>  $items
     * @param  string  $dateField  Attribute holding the item's date (Carbon or parseable string).
     * @param  callable(T): array<string, int>  $extractor  Series increments for one item.
     * @param  int  $days  Window size in days, ending today.
     * @param  array<int, string>  $seriesKeys  Series keys guaranteed in the result, zero-filled when no item maps to them.
     * @return array<string, list<int>|list<string>> Labels (Y-m-d, oldest to today) plus one int series per key.
     */
    public static function bucketDaily(Collection $items, string $dateField, callable $extractor, int $days = 14, array $seriesKeys = []): array
    {
        $labels = [];
        $dayIndex = [];
        $today = Carbon::today();

        for ($i = $days - 1; $i >= 0; $i--) {
            $label = $today->copy()->subDays($i)->toDateString();
            $labels[] = $label;
            $dayIndex[$label] = $days - 1 - $i;
        }

        $series = collect($seriesKeys)
            ->mapWithKeys(fn (string $key): array => [$key => array_fill(0, $days, 0)])
            ->all();

        foreach ($items as $item) {
            $date = data_get($item, $dateField);
            $label = $date !== null ? Carbon::parse($date)->toDateString() : null;

            if ($label === null || ! isset($dayIndex[$label])) {
                continue;
            }

            $index = $dayIndex[$label];

            foreach ($extractor($item) as $key => $value) {
                if (! isset($series[$key])) {
                    $series[$key] = array_fill(0, $days, 0);
                }

                $series[$key][$index] += $value;
            }
        }

        return array_merge(['labels' => $labels], $series);
    }

    /**
     * Health props: provider chain, Telegram and bot wiring.
     *
     * @return array{
     *     providers: list<array{id: int, provider: string, is_enabled: bool, model_text: string|null, has_credentials: bool, failover_order: int}>,
     *     telegram: array{bot_configured: bool, polling_enabled: bool, allowed_users_count: int},
     *     owner: array{name: string}|null
     * }
     */
    private function healthProps(): array
    {
        $telegram = TelegramSetting::singleton();
        $botSettings = BotSetting::singleton();
        $owner = $botSettings->owner;

        $providers = AiProvider::query()
            ->orderBy('failover_order')
            ->get()
            ->map(fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'provider' => $provider->provider->value,
                'is_enabled' => (bool) $provider->is_enabled,
                'model_text' => $provider->model_text,
                'has_credentials' => filled($provider->api_key),
                'failover_order' => $provider->failover_order,
            ])
            ->all();

        return [
            'providers' => $providers,
            'telegram' => [
                'bot_configured' => filled($telegram->bot_token),
                'polling_enabled' => (bool) $telegram->polling_enabled,
                'allowed_users_count' => count($telegram->allowed_user_ids ?? []),
            ],
            'owner' => $owner !== null ? ['name' => $owner->name] : null,
        ];
    }

    /**
     * Activity props: conversation and message volumes.
     *
     * @return array{
     *     total_conversations: int,
     *     total_messages: int,
     *     linked_chats: int,
     *     user_messages: int,
     *     assistant_messages: int,
     *     messages_by_day: array{labels: list<string>, user: list<int>, assistant: list<int>}
     * }
     */
    private function activityProps(): array
    {
        $roleCounts = ConversationMessage::query()
            ->whereIn('role', ['user', 'assistant'])
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $messages = ConversationMessage::query()
            ->where('created_at', '>=', Carbon::today()->subDays(13))
            ->get(['role', 'created_at']);

        return [
            'total_conversations' => Conversation::query()->count(),
            'total_messages' => (int) $roleCounts->sum(),
            'linked_chats' => TelegramChatConversation::query()->count(),
            'user_messages' => (int) ($roleCounts['user'] ?? 0),
            'assistant_messages' => (int) ($roleCounts['assistant'] ?? 0),
            'messages_by_day' => static::bucketDaily(
                $messages,
                'created_at',
                fn (ConversationMessage $message): array => [$message->role => 1],
                14,
                ['user', 'assistant'],
            ),
        ];
    }

    /**
     * Usage props: token totals, daily series and per provider/model breakdowns.
     *
     * @return array{
     *     total_tokens: array{prompt: int, completion: int, reasoning: int},
     *     tokens_by_day: array{labels: list<string>, prompt: list<int>, completion: list<int>},
     *     by_provider: list<array{provider: string, prompt_tokens: int, completion_tokens: int, messages: int}>,
     *     by_model: list<array{model: string, prompt_tokens: int, completion_tokens: int, messages: int}>
     * }
     */
    private function usageProps(): array
    {
        $daily = ConversationMessage::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', Carbon::today()->subDays(13))
            ->get(['usage', 'created_at']);

        return [
            'total_tokens' => $this->tokenTotals(),
            'tokens_by_day' => static::bucketDaily(
                $daily,
                'created_at',
                fn (ConversationMessage $message): array => [
                    'prompt' => (int) ($message->usage['prompt_tokens'] ?? 0),
                    'completion' => (int) ($message->usage['completion_tokens'] ?? 0),
                ],
                14,
                ['prompt', 'completion'],
            ),
            'by_provider' => $this->tokenBreakdown('provider'),
            'by_model' => $this->tokenBreakdown('model'),
        ];
    }

    /**
     * Aggregate token totals for assistant messages entirely in SQL.
     *
     * @return array{prompt: int, completion: int, reasoning: int}
     */
    private function tokenTotals(): array
    {
        $totals = ConversationMessage::query()
            ->where('role', 'assistant')
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(usage, '$.prompt_tokens')), 0) as prompt")
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(usage, '$.completion_tokens')), 0) as completion")
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(usage, '$.reasoning_tokens')), 0) as reasoning")
            ->toBase()
            ->first();

        return [
            'prompt' => (int) $totals->prompt,
            'completion' => (int) $totals->completion,
            'reasoning' => (int) $totals->reasoning,
        ];
    }

    /**
     * Token usage grouped by a meta key, ordered by total tokens descending.
     *
     * @param  string  $key  Meta key to group by ('provider' or 'model').
     * @return list<array<string, int|string>>
     */
    private function tokenBreakdown(string $key): array
    {
        $extract = "COALESCE(JSON_EXTRACT(meta, '\$.{$key}'), 'unknown')";

        return ConversationMessage::query()
            ->where('role', 'assistant')
            ->selectRaw("{$extract} as {$key}")
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(usage, '$.prompt_tokens')), 0) as prompt_tokens")
            ->selectRaw("COALESCE(SUM(JSON_EXTRACT(usage, '$.completion_tokens')), 0) as completion_tokens")
            ->selectRaw('COUNT(*) as messages')
            ->groupByRaw($extract)
            ->orderByRaw('(prompt_tokens + completion_tokens) DESC')
            ->orderBy($key)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                $key => (string) $row->{$key},
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'messages' => (int) $row->messages,
            ])
            ->values()
            ->all();
    }
}
