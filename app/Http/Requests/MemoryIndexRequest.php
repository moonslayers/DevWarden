<?php

namespace App\Http\Requests;

use App\Enums\BotMemoryCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemoryIndexRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(BotMemoryCategory::values())],
            'sort' => ['nullable', Rule::in(['created_at', 'importance', 'access_count'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
