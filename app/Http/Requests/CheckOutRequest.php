<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A check-out, for one room or a whole reservation, optionally with an
 * earlier actual time today (FR-010a).
 */
class CheckOutRequest extends FormRequest
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
            'checked_out_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
