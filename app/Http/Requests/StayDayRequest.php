<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The day a front-desk list is for (default: the hotel's today), and the
 * hotel a super admin is looking at.
 */
class StayDayRequest extends FormRequest
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
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'hotel_id' => ['sometimes', 'uuid'],
        ];
    }
}
