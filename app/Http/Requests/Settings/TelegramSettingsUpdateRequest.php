<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TelegramSettingsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bot_token' => ['nullable', 'string', 'max:255'],
            'allowed_user_ids' => ['nullable', 'array'],
            'allowed_user_ids.*' => ['integer', 'min:1'],
            'polling_enabled' => ['boolean'],
        ];
    }

    /**
     * Normalize the allowed user IDs before validation, accepting a
     * comma-separated string or an array of integers.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'allowed_user_ids' => $this->normalizeAllowedUserIds($this->input('allowed_user_ids')),
        ]);
    }

    private function normalizeAllowedUserIds(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(
                fn (mixed $id): int|string => is_numeric($id) ? (int) $id : $id,
                $value,
            ));
        }

        if (is_null($value) || (is_string($value) && trim($value) === '')) {
            return [];
        }

        if (is_string($value)) {
            return array_values(array_map(
                fn (string $part): int|string => is_numeric($part) ? (int) $part : $part,
                array_map('trim', explode(',', $value)),
            ));
        }

        return [];
    }
}
