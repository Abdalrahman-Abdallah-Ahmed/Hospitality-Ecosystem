<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:255'],
            'guest_id' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'string', 'max:255'],
            'guest' => ['nullable', 'array'],
            'guest.first_name' => ['nullable', 'string', 'max:255'],
            'guest.last_name' => ['nullable', 'string', 'max:255'],
            'guest.phone_number' => ['nullable', 'string', 'max:255'],
            'guest.email' => ['nullable', 'string', 'email', 'max:255'],
            'room_id' => ['nullable', 'string', 'exists:rooms,id'],
            'reservation_id' => ['nullable', 'string', 'max:255', 'unique:reservations,reservation_id'],
            'arrival_date' => ['required', 'date'],
            'departure_date' => ['required', 'date', 'after:arrival_date'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'adults' => ['nullable', 'integer', 'min:1'],
            'children' => ['nullable', 'integer', 'min:0'],
            'special_requests' => ['nullable', 'string'],
            'reservation_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
