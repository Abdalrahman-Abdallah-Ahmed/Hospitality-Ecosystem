<?php

namespace App\Http\Requests;

use App\Enums\OutcomeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordRecommendationOutcomeRequest extends FormRequest
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
            // EXPIRED is absent on purpose: it means "the guest departed
            // undecided", which the nightly job concludes by elimination
            // rather than something a person observes and reports.
            'outcome' => ['required', Rule::in([
                OutcomeType::NOT_DELIVERED->value,
                OutcomeType::DELIVERED->value,
                OutcomeType::DECLINED->value,
                OutcomeType::ACCEPTED->value,
                OutcomeType::BOOKED->value,
            ])],
            'channel' => ['nullable', Rule::in(['whatsapp', 'face_to_face', 'phone', 'email'])],
            // A refusal with no reason tells you nothing you can act on.
            'decline_reason' => ['nullable', 'string', 'max:1000', 'required_if:outcome,'.OutcomeType::DECLINED->value],
            // The conversion is the commitment, so claiming one requires it.
            'booking_id' => ['nullable', 'string', 'exists:bookings,id', 'required_if:outcome,'.OutcomeType::BOOKED->value],
            'evidence_quote' => ['nullable', 'string', 'max:2000'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
