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
            'guest_id' => ['required', 'string', 'exists:guests,id'],
            // Nullable on purpose: a guest can book something that is not in
            // the catalogue yet, and item_name carries it until it is.
            'activity_id' => ['nullable', 'string', 'exists:activities,id'],
            'item_name' => ['nullable', 'string', 'max:255', 'required_without:activity_id'],
            'recommendation_id' => ['nullable', 'string', 'exists:recommendations,id'],
            'stay_id' => ['nullable', 'string', 'exists:stays,id'],

            'scheduled_for' => ['nullable', 'date'],
            'pax' => ['nullable', 'integer', 'min:1'],

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
