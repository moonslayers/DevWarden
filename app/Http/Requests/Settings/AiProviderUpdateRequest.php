<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiProviderUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isCompatible = $this->boundProvider()?->provider === AiProviderType::OpenAiCompatible;

        return [
            'is_enabled' => ['boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'base_url' => $isCompatible
                ? ['required', 'url', 'max:255']
                : ['nullable', 'url', 'max:255'],
            'model_text' => ['nullable', 'string', 'max:255'],
            'failover_order' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Normalize the enabled state before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled'),
        ]);
    }

    private function boundProvider(): ?AiProvider
    {
        $routeProvider = $this->route('provider');

        return $routeProvider instanceof AiProvider ? $routeProvider : null;
    }
}
