<?php

namespace App\Http\Requests;

use App\Enums\ChargeModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
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
            'guest_id' => ['required', 'uuid', 'exists:guests,id'],
            // Nullable on purpose: a guest can book something that is not in
            // the catalogue yet, and item_name carries it until it is.
            'activity_id' => ['nullable', 'uuid', 'exists:activities,id'],
            'item_name' => ['nullable', 'string', 'max:255', 'required_without:activity_id'],
            'recommendation_id' => ['nullable', 'uuid', 'exists:recommendations,id'],
            'stay_id' => ['nullable', 'uuid', 'exists:stays,id'],
            'reservation_id' => ['nullable', 'uuid', 'exists:reservations,id'],

            // A time with no offset is the hotel's local time. A catalogue
            // booking needs a date; scheduled_date alone books an activity
            // that runs all day.
            'scheduled_for' => [
                'nullable', 'date',
                Rule::requiredIf(fn () => $this->filled('activity_id') && ! $this->filled('scheduled_date')),
            ],
            'scheduled_date' => ['nullable', 'date_format:Y-m-d'],
            'pax' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'capacity_override' => ['nullable', 'boolean'],

            // How it is paid for, if at all. 'included' means no money will
            // ever move and the booking is still a complete success.
            'charge_model' => ['required', Rule::enum(ChargeModel::class)],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],

            // 'recommendation' is not accepted here — it is derived from
            // recommendation_id, so origin can never claim a credit the link
            // does not support.
            'origin' => ['nullable', Rule::in(['staff', 'guest_request'])],
            'channel' => ['nullable', Rule::in(['face_to_face', 'phone', 'desk', 'email', 'whatsapp'])],
        ];
    }
}
