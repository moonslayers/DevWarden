<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\SkillStoreRequest;
use App\Http\Requests\Settings\SkillUpdateRequest;
use App\Models\BotSkill;
use App\Models\SkillUsageLog;
use App\Services\TimeSeriesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    public function __construct(protected TimeSeriesService $timeSeries)
    {
        //
    }

    /**
     * Show the bot skills page.
     */
    public function index(): Response
    {
        return Inertia::render('Skills', [
            'skills' => BotSkill::ordered()->get(),
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Create a new bot skill.
     */
    public function store(SkillStoreRequest $request): RedirectResponse
    {
        BotSkill::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill created.')]);

        return back();
    }

    /**
     * Update a bot skill.
     */
    public function update(SkillUpdateRequest $request, BotSkill $skill): RedirectResponse
    {
        $skill->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill updated.')]);

        return back();
    }

    /**
     * Delete a bot skill.
     */
    public function destroy(BotSkill $skill): RedirectResponse
    {
        $skill->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Skill deleted.')]);

        return back();
    }

    /**
     * Usage stats for the skills page.
     *
     * @return array{
     *     total_matches: int,
     *     active_count: int,
     *     inactive_count: int,
     *     matches_by_day: array{labels: list<string>, count: list<int>},
     *     top_skills: list<array{id: int, name: string, match_count: int}>
     * }
     */
    private function stats(): array
    {
        $logs = SkillUsageLog::query()
            ->where('created_at', '>=', Carbon::today()->subDays(13))
            ->get(['created_at']);

        $topSkills = SkillUsageLog::query()
            ->join('bot_skills', 'bot_skills.id', '=', 'skill_usage_logs.skill_id')
            ->selectRaw('skill_usage_logs.skill_id, bot_skills.name, COUNT(*) as match_count')
            ->groupBy('skill_usage_logs.skill_id', 'bot_skills.name')
            ->orderByDesc('match_count')
            ->orderBy('bot_skills.name')
            ->limit(5)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->skill_id,
                'name' => (string) $row->name,
                'match_count' => (int) $row->match_count,
            ])
            ->all();

        return [
            'total_matches' => (int) SkillUsageLog::query()->count(),
            'active_count' => (int) BotSkill::query()->where('active', true)->count(),
            'inactive_count' => (int) BotSkill::query()->where('active', false)->count(),
            'matches_by_day' => $this->timeSeries->bucketDaily(
                $logs,
                'created_at',
                fn (SkillUsageLog $log): array => ['count' => 1],
                14,
                ['count'],
            ),
            'top_skills' => $topSkills,
        ];
    }
}
