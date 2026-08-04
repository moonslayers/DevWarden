<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AiProviderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AiProviderStoreRequest;
use App\Http\Requests\Settings\AiProviderUpdateRequest;
use App\Models\AiProvider;
use App\Services\AiConfigSyncer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    public function __construct(protected AiConfigSyncer $syncer)
    {
        //
    }

    /**
     * Show the AI providers settings page.
     */
    public function index(): Response
    {
        $providers = AiProvider::query()
            ->orderBy('failover_order')
            ->get()
            ->map(fn (AiProvider $provider): array => [
                'id' => $provider->id,
                'provider' => $provider->provider->value,
                'is_enabled' => (bool) $provider->is_enabled,
                'base_url' => $provider->base_url,
                'model_text' => $provider->model_text,
                'failover_order' => $provider->failover_order,
                'has_api_key' => filled($provider->api_key),
            ]);

        return Inertia::render('settings/Providers', [
            'providers' => $providers->all(),
            'provider_types' => $this->providerTypeOptions(),
        ]);
    }

    /**
     * Create a new AI provider.
     */
    public function store(AiProviderStoreRequest $request): RedirectResponse
    {
        AiProvider::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider added.')]);

        return back();
    }

    /**
     * Update an AI provider, keeping the existing API key when a blank one is submitted.
     */
    public function update(AiProviderUpdateRequest $request, AiProvider $provider): RedirectResponse
    {
        $data = $request->validated();

        $provider->fill([
            'is_enabled' => $data['is_enabled'],
            'base_url' => $data['base_url'] ?? null,
            'model_text' => $data['model_text'] ?? null,
            'failover_order' => $data['failover_order'],
        ]);

        if (filled($data['api_key'] ?? null)) {
            $provider->api_key = $data['api_key'];
        }

        $provider->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider updated.')]);

        return back();
    }

    /**
     * Delete an AI provider.
     */
    public function destroy(AiProvider $provider): RedirectResponse
    {
        $provider->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI provider removed.')]);

        return back();
    }

    /**
     * Test the connection to an AI provider.
     */
    public function test(AiProvider $provider): RedirectResponse
    {
        $connected = $this->syncer->testConnection($provider);

        Inertia::flash('toast', [
            'type' => $connected ? 'success' : 'error',
            'message' => $connected
                ? __('Connected to :provider successfully.', ['provider' => $provider->provider->value])
                : __('Could not connect to :provider.', ['provider' => $provider->provider->value]),
        ]);

        return back();
    }

    /**
     * Get the selectable provider types with display labels.
     *
     * @return list<array{value: string, label: string}>
     */
    private function providerTypeOptions(): array
    {
        $labels = [
            AiProviderType::OpenAI->value => 'OpenAI',
            AiProviderType::Anthropic->value => 'Anthropic',
            AiProviderType::DeepSeek->value => 'DeepSeek',
            AiProviderType::OpenAiCompatible->value => 'OpenAI Compatible',
        ];

        return collect(AiProviderType::cases())
            ->map(fn (AiProviderType $type): array => [
                'value' => $type->value,
                'label' => $labels[$type->value],
            ])
            ->values()
            ->all();
    }
}
