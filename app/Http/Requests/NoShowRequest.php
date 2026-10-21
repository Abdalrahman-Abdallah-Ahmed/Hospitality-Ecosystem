<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Marking or undoing a no-show (SPEC-012). The rules that need the hotel and
 * the reservation live in NoShowService.
 */
class NoShowRequest extends FormRequest
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
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'overbook_override' => ['sometimes', 'boolean'],
        ];
    }
}
