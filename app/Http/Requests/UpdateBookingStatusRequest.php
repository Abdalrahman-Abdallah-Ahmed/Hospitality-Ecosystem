<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookingStatusRequest extends FormRequest
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
            // 'pending' is absent: a booking starts there and only moves
            // forward. Reopening one would let a stale process resurrect a
            // commitment the guest withdrew.
            'status' => ['required', Rule::in([
                BookingStatus::CONFIRMED->value,
                BookingStatus::REALISED->value,
                BookingStatus::NO_SHOW->value,
                BookingStatus::CANCELLED->value,
            ])],
            // A cancellation with no reason tells nobody anything.
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:status,'.BookingStatus::CANCELLED->value],
            'realised_at' => ['nullable', 'date'],
        ];
    }
}
