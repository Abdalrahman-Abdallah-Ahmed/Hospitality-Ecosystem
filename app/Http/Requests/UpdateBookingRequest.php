<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What staff may change on a live booking (SPEC-043). Who it is for, what it
 * was credited to and how it came about are fixed for good; its status moves
 * only through the status endpoint.
 */
class UpdateBookingRequest extends FormRequest
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
            'activity_id' => ['sometimes', 'nullable', 'uuid', 'exists:activities,id'],
            // A time with no offset is the hotel's local time.
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'scheduled_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'pax' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'item_name' => ['sometimes', 'string', 'max:255'],
            'reservation_id' => ['sometimes', 'nullable', 'uuid', 'exists:reservations,id'],
            'stay_id' => ['sometimes', 'nullable', 'uuid', 'exists:stays,id'],
            'capacity_override' => ['nullable', 'boolean'],

            'guest_id' => ['prohibited'],
            'recommendation_id' => ['prohibited'],
            'origin' => ['prohibited'],
            'reference' => ['prohibited'],
            'status' => ['prohibited'],
            'charge_model' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
        ];
    }
}
