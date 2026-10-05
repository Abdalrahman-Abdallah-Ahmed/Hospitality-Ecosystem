<?php

namespace App\Http\Requests;

use App\Enums\InspectionResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The result of an inspection; a fail needs the note that goes on the
 * re-clean task (FR-005).
 */
class InspectionRequest extends FormRequest
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
            'result' => ['required', Rule::enum(InspectionResult::class)],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:result,fail'],
        ];
    }
}
