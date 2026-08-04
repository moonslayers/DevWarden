<?php

namespace App\Http\Requests\Settings;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiProviderStoreRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::enum(AiProviderType::class), Rule::unique('ai_providers', 'provider')],
            'is_enabled' => ['boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:255', 'required_if:provider,openai-compatible'],
            'model_text' => ['nullable', 'string', 'max:255'],
            'failover_order' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Default the enabled state and append to the failover chain when not provided.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled', true),
        ]);

        if (! $this->has('failover_order') || $this->input('failover_order') === null) {
            $this->merge([
                'failover_order' => ((int) AiProvider::max('failover_order')) + 1,
            ]);
        }
    }
}
