<?php

namespace App\Http\Requests;

use App\Enums\AiActivityCategory;
use App\Enums\AiActivityResult;
use App\Enums\AiAgentName;
use App\Support\Audit\HistorySubjects;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the AI activity list (SPEC-083 FR-025). `category` takes one
 * value or several, comma-separated.
 */
class AiActivityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('category'))) {
            $this->merge(['category' => array_values(array_filter(explode(',', $this->input('category'))))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'array'],
            'category.*' => [Rule::enum(AiActivityCategory::class)],
            'agent' => ['nullable', Rule::enum(AiAgentName::class)],
            'tool' => ['nullable', 'string', 'max:255'],
            'result' => ['nullable', Rule::enum(AiActivityResult::class)],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'ai_run_id' => ['nullable', 'uuid'],
            'acted_for_type' => ['nullable', 'required_with:acted_for_id', Rule::in(['guest', 'user'])],
            'acted_for_id' => ['nullable', 'uuid'],
            'target_type' => ['nullable', Rule::in(HistorySubjects::slugs())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }
}
