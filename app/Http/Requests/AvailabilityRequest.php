<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of an availability lookup. The rules that need the hotel (not
 * before its today, at most 90 nights) are AvailabilityService::assertRange(),
 * which the AI tools share.
 */
class AvailabilityRequest extends FormRequest
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
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after:arrival_date'],
            'room_type_ids' => ['sometimes', 'array'],
            'room_type_ids.*' => ['uuid', 'distinct'],
            'hotel_id' => ['sometimes', 'uuid'],
        ];
    }
}
