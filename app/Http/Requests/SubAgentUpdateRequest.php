<?php

namespace App\Http\Requests;

use App\Enums\BotSubAgentType;
use App\Models\BotSubAgent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubAgentUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $subAgent = $this->boundSubAgent();
        $activatingVision = $this->isActivatingVision($subAgent);

        $providerRule = $activatingVision
            ? Rule::exists('ai_providers', 'id')->where('is_enabled', true)
            : 'exists:ai_providers,id';

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('bot_sub_agents', 'slug')->ignore($subAgent)],
            'description' => ['nullable', 'string'],
            'system_prompt' => ['nullable', 'string'],
            'ai_provider_id' => ['nullable', $providerRule, Rule::requiredIf($activatingVision)],
            'model' => ['nullable', 'string', 'max:255', Rule::requiredIf($activatingVision)],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }

    /**
     * Default the enabled state and sort order before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => $this->boolean('is_active'),
            ]);
        }

        $this->merge([
            'sort_order' => $this->input('sort_order') ?? 0,
        ]);
    }

    /**
     * Whether this update activates a vision sub-agent, which requires a provider and model.
     */
    private function isActivatingVision(?BotSubAgent $subAgent): bool
    {
        return $subAgent !== null
            && $subAgent->type === BotSubAgentType::Vision
            && $this->boolean('is_active');
    }

    private function boundSubAgent(): ?BotSubAgent
    {
        $routeSubAgent = $this->route('subAgent');

        return $routeSubAgent instanceof BotSubAgent ? $routeSubAgent : null;
    }
}
