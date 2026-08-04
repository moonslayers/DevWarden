<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BotSettingsUpdateRequest;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    /**
     * Show the bot settings page.
     */
    public function edit(): Response
    {
        $settings = BotSetting::singleton();

        return Inertia::render('settings/Bot', [
            'system_prompt' => $settings->system_prompt,
            'max_history_messages' => (int) $settings->max_history_messages,
            'owner_user_id' => $settings->owner_user_id,
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])
                ->all(),
        ]);
    }

    /**
     * Update the bot settings.
     */
    public function update(BotSettingsUpdateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        BotSetting::singleton()->fill([
            'system_prompt' => $data['system_prompt'] ?? null,
            'max_history_messages' => $data['max_history_messages'] ?? 50,
            'owner_user_id' => $data['owner_user_id'] ?? null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bot settings updated.')]);

        return back();
    }
}
