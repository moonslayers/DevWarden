<?php

namespace App\Providers;

use App\Models\OpencodeSetting;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\LocalEmbeddingService;
use App\Services\Opencode\OpencodeSessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A single shared MCP client across every opencode tool call of a prompt.
        $this->app->singleton(OpencodeSessionManager::class);

        // A single shared in-process pipeline so the ONNX model is loaded only once.
        $this->app->singleton(EmbeddingService::class, LocalEmbeddingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->registerScheduleWorkBootListener();
    }

    /**
     * Anchor the opencode session-watch boot to the real schedule:work start.
     *
     * The scheduled monitor detects a service restart by the session-watch
     * watermark, but a restart of dev:full inside the ten-minute window is
     * invisible to that heuristic. The watcher therefore treats
     * schedule_booted_at as the authoritative restart signal, so stamping it
     * when schedule:work actually boots (and re-arming the boot summary marker)
     * lets the watcher emit the boot summary even on rapid restarts.
     */
    protected function registerScheduleWorkBootListener(): void
    {
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command !== 'schedule:work') {
                return;
            }

            try {
                $settings = OpencodeSetting::singleton();
                $settings->schedule_booted_at = now();
                $settings->session_watch_boot_reported_at = null;
                $settings->save();
            } catch (Throwable $e) {
                Log::debug('OpencodeSessionWatcher: failed to stamp the schedule:work boot anchor.', [
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
