<?php

namespace App\Http\Requests;

use App\Enums\ImportRowOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lists one import's row results: filtered by outcome, or to rows with
 * warnings, in row order.
 */
class ReservationImportRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['nullable', Rule::enum(ImportRowOutcome::class)],
            'has_warnings' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
