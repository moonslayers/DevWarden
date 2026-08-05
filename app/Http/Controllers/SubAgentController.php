<?php

namespace App\Http\Controllers;

use App\Enums\AiProviderType;
use App\Enums\BotSubAgentType;
use App\Http\Requests\SubAgentStoreRequest;
use App\Http\Requests\SubAgentUpdateRequest;
use App\Models\AiProvider;
use App\Models\BotSubAgent;
use App\Models\SubAgentUsageLog;
use App\Services\TimeSeriesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SubAgentController extends Controller
{
    public function __construct(protected TimeSeriesService $timeSeries)
    {
        //
    }

    /**
     * Show the sub-agents main page.
     */
    public function index(): Response
    {
        $subAgents = BotSubAgent::query()->ordered()->get();

        $usage = SubAgentUsageLog::query()
            ->selectRaw('sub_agent_id, COUNT(*) as invocations, COALESCE(SUM(tokens), 0) as tokens')
            ->groupBy('sub_agent_id')
            ->get()
            ->keyBy('sub_agent_id');

        return Inertia::render('subagents/Index', [
            'subAgents' => $subAgents
                ->map(function (BotSubAgent $subAgent) use ($usage): array {
                    $usageRow = $usage->get($subAgent->id);

                    return [
                        'id' => $subAgent->id,
                        'name' => $subAgent->name,
                        'slug' => $subAgent->slug,
                        'type' => $subAgent->type->value,
                        'description' => $subAgent->description,
                        'system_prompt' => $subAgent->system_prompt,
                        'ai_provider_id' => $subAgent->ai_provider_id,
                        'model' => $subAgent->model,
                        'is_active' => (bool) $subAgent->is_active,
                        'is_system' => (bool) $subAgent->is_system,
                        'uses_system_provider' => $subAgent->usesSystemProvider(),
                        'sort_order' => $subAgent->sort_order,
                        'invocations' => (int) ($usageRow?->invocations ?? 0),
                        'tokens' => (int) ($usageRow?->tokens ?? 0),
                    ];
                })
                ->all(),
            'providers' => $this->providerOptions(),
            'types' => $this->typeOptions(),
            'stats' => $this->stats($subAgents, $usage),
        ]);
    }

    /**
     * Create a new general sub-agent.
     */
    public function store(SubAgentStoreRequest $request): RedirectResponse
    {
        BotSubAgent::create([
            ...$request->validated(),
            'type' => BotSubAgentType::General,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sub-agent created.')]);

        return back();
    }

    /**
     * Update a sub-agent, locking identity fields on system rows.
     */
    public function update(SubAgentUpdateRequest $request, BotSubAgent $subAgent): RedirectResponse
    {
        $data = $request->validated();

        if ($subAgent->is_system) {
            $subAgent->update([
                'system_prompt' => $data['system_prompt'] ?? null,
                'ai_provider_id' => $data['ai_provider_id'] ?? null,
                'model' => $data['model'] ?? null,
                'is_active' => $data['is_active'] ?? $subAgent->is_active,
            ]);
        } else {
            $subAgent->update($data);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sub-agent updated.')]);

        return back();
    }

    /**
     * Delete a sub-agent, protecting system rows.
     */
    public function destroy(BotSubAgent $subAgent): RedirectResponse
    {
        if ($subAgent->is_system) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The system sub-agent cannot be deleted.')]);

            return back();
        }

        $subAgent->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sub-agent deleted.')]);

        return back();
    }

    /**
     * Stats over sub-agents and their usage logs.
     *
     * @param  Collection<int, BotSubAgent>  $subAgents
     * @param  Collection<int, SubAgentUsageLog>  $usage  Per sub-agent usage keyed by sub_agent_id.
     * @return array{
     *     total: int,
     *     active: int,
     *     visionActive: bool,
     *     generalCount: int,
     *     totalInvocations: int,
     *     totalTokens: int,
     *     invocationsByKind: array{describe: int, ask: int},
     *     invocationsLast14d: list<array{date: string, total: int}>,
     *     tokensLast14d: list<array{date: string, total: int}>
     * }
     */
    private function stats(Collection $subAgents, Collection $usage): array
    {
        $logs = SubAgentUsageLog::query()
            ->where('created_at', '>=', Carbon::today()->subDays(13))
            ->get(['tokens', 'created_at']);

        $daily = $this->timeSeries->bucketDaily(
            $logs,
            'created_at',
            fn (SubAgentUsageLog $log): array => [
                'invocations' => 1,
                'tokens' => (int) ($log->tokens ?? 0),
            ],
            14,
            ['invocations', 'tokens'],
        );

        $days = collect($daily['labels'])->map(fn (string $label, int $index): array => [
            'date' => $label,
            'invocations' => $daily['invocations'][$index],
            'tokens' => $daily['tokens'][$index],
        ]);

        return [
            'total' => $subAgents->count(),
            'active' => $subAgents->where('is_active', true)->count(),
            'visionActive' => BotSubAgent::activeVision() !== null,
            'generalCount' => $subAgents->where('type', BotSubAgentType::General)->count(),
            'totalInvocations' => (int) $usage->sum('invocations'),
            'totalTokens' => (int) $usage->sum('tokens'),
            'invocationsByKind' => [
                'describe' => SubAgentUsageLog::query()->byKind('describe')->count(),
                'ask' => SubAgentUsageLog::query()->byKind('ask')->count(),
            ],
            'invocationsLast14d' => $days
                ->map(fn (array $day): array => ['date' => $day['date'], 'total' => $day['invocations']])
                ->all(),
            'tokensLast14d' => $days
                ->map(fn (array $day): array => ['date' => $day['date'], 'total' => $day['tokens']])
                ->all(),
        ];
    }

    /**
     * Selectable AI providers ordered for the failover chain.
     *
     * Only enabled providers are selectable, except any provider currently
     * referenced by a sub-agent, which stays selectable so existing rows remain
     * editable even when the provider is disabled. The first enabled provider
     * in failover order is flagged as the main provider.
     *
     * @return list<array{id: int, label: string, base_url: string|null, is_main: bool}>
     */
    private function providerOptions(): array
    {
        $referencedProviderIds = BotSubAgent::query()
            ->whereNotNull('ai_provider_id')
            ->distinct()
            ->pluck('ai_provider_id');

        $mainProvider = AiProvider::enabledOrdered()->first();

        return AiProvider::query()
            ->where('is_enabled', true)
            ->orWhereIn('id', $referencedProviderIds)
            ->orderBy('failover_order')
            ->get()
            ->map(fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'label' => $provider->provider->value,
                'base_url' => $provider->provider === AiProviderType::OpenAiCompatible ? $provider->base_url : null,
                'is_main' => $mainProvider !== null && $provider->id === $mainProvider->id,
            ])
            ->all();
    }

    /**
     * Selectable sub-agent types.
     *
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return collect(BotSubAgentType::cases())
            ->map(fn (BotSubAgentType $type): array => [
                'value' => $type->value,
                'label' => $type->name,
            ])
            ->values()
            ->all();
    }
}
