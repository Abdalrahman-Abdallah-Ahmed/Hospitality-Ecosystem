<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The conversation list and one conversation's pages (SPEC-082). No search:
 * conversation search is post-MVP (D15), so a search parameter is not read.
 */
class ConversationIndexRequest extends FormRequest
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
            'guest_id' => ['nullable', 'uuid'],
            'last_active_from' => ['nullable', 'date_format:Y-m-d'],
            'last_active_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:last_active_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }
}
