<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\TelegramSettingsUpdateRequest;
use App\Models\TelegramSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TelegramController extends Controller
{
    /**
     * Show the Telegram settings page.
     */
    public function edit(): Response
    {
        $settings = TelegramSetting::singleton();

        return Inertia::render('settings/Telegram', [
            'has_bot_token' => filled($settings->bot_token),
            'allowed_user_ids' => $settings->allowed_user_ids ?? [],
            'polling_enabled' => (bool) $settings->polling_enabled,
        ]);
    }

    /**
     * Update the Telegram settings.
     */
    public function update(TelegramSettingsUpdateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $settings = TelegramSetting::singleton();

        $settings->fill([
            'allowed_user_ids' => $data['allowed_user_ids'] ?? [],
            'polling_enabled' => $data['polling_enabled'] ?? false,
        ]);

        if (filled($data['bot_token'] ?? null)) {
            $settings->bot_token = $data['bot_token'];
        }

        $settings->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram settings updated.')]);

        return back();
    }
}
