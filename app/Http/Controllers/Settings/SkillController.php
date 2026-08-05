<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SkillStoreRequest;
use App\Http\Requests\Settings\SkillUpdateRequest;
use App\Models\BotSkill;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    /**
     * Show the bot skills settings page.
     */
    public function index(): Response
    {
        return Inertia::render('settings/Skills', [
            'skills' => BotSkill::ordered()->get(),
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
}
