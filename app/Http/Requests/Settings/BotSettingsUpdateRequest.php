<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BotSettingsUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'system_prompt' => ['nullable', 'string'],
            'max_history_messages' => ['integer', 'min:1', 'max:200'],
            'owner_user_id' => ['nullable', 'exists:users,id'],
        ];
    }

    /**
     * Default the history depth to 50 when the field is absent.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('max_history_messages')) {
            $this->merge(['max_history_messages' => 50]);
        }
    }
}
