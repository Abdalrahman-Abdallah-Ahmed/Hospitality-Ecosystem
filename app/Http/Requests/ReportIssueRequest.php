<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A room issue found on a housekeeping task (FR-022).
 */
class ReportIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:2000'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'room_unsellable' => ['sometimes', 'boolean'],
        ];
    }
}
