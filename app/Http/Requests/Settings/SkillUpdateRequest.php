<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SkillUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('bot_skills', 'slug')->ignore($this->route('skill'))],
            'description' => ['nullable', 'string'],
            'content' => ['required', 'string'],
            'trigger_keywords' => ['nullable', 'array', 'max:50'],
            'trigger_keywords.*' => ['string', 'max:100'],
            'active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Normalize the comma-separated keywords, enabled flag and sort order before validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'trigger_keywords' => $this->normalizeTriggerKeywords(),
            'active' => $this->boolean('active'),
            'sort_order' => $this->input('sort_order') ?? 0,
        ]);
    }

    /**
     * Convert a comma-separated keyword string (or array) into a trimmed string array.
     *
     * @return array<int, string>|null
     */
    private function normalizeTriggerKeywords(): ?array
    {
        $keywords = $this->input('trigger_keywords');

        if ($keywords === null || $keywords === '') {
            return null;
        }

        $normalized = collect(is_array($keywords) ? $keywords : explode(',', $keywords))
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '')
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }
}
