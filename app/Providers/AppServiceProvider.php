<?php

namespace App\Providers;

use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\LocalEmbeddingService;
use App\Services\Opencode\OpencodeSessionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
