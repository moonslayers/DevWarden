<?php

use App\Http\Controllers\Settings\AiProviderController;
use App\Http\Controllers\Settings\BotController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SkillController;
use App\Http\Controllers\Settings\TelegramController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});

// --- T4.1: Telegram settings ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/telegram', [TelegramController::class, 'edit'])->name('telegram.edit');
    Route::patch('settings/telegram', [TelegramController::class, 'update'])->name('telegram.update');
});

// --- T4.2: AI Providers settings ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/providers', [AiProviderController::class, 'index'])->name('providers.index');
    Route::post('settings/providers', [AiProviderController::class, 'store'])->name('providers.store');
    Route::patch('settings/providers/{provider}', [AiProviderController::class, 'update'])->name('providers.update');
    Route::delete('settings/providers/{provider}', [AiProviderController::class, 'destroy'])->name('providers.destroy');
    Route::post('settings/providers/{provider}/test', [AiProviderController::class, 'test'])->name('providers.test');
});

// --- T4.3: Bot settings ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/bot', [BotController::class, 'edit'])->name('bot.edit');
    Route::patch('settings/bot', [BotController::class, 'update'])->name('bot.update');
});

// --- Skills settings ---
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/skills', [SkillController::class, 'index'])->name('settings.skills.index');
    Route::post('settings/skills', [SkillController::class, 'store'])->name('settings.skills.store');
    Route::patch('settings/skills/{skill}', [SkillController::class, 'update'])->name('settings.skills.update');
    Route::delete('settings/skills/{skill}', [SkillController::class, 'destroy'])->name('settings.skills.destroy');
});
