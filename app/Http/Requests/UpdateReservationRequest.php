<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericUpdateRequest;
use App\Support\Reservations\ReservationRoomSync;

/**
 * The schema-derived partial-update rules plus the optional desired list of
 * room lines. When `rooms` is sent it replaces the live lines (see
 * ReservationRoomSync::diff()); when it is absent the lines are untouched.
 */
class UpdateReservationRequest extends GenericUpdateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = ReservationRoomSync::MAX_UNITS;

        return [
            ...parent::rules(),
            'room_id' => ['prohibited'],
            // No min: an empty list means "remove every line", which the
            // domain rejects with a message that says what to do instead.
            'rooms' => ['sometimes', 'present', 'array', "max:{$max}"],
            'rooms.*' => ['array'],
            'rooms.*.id' => ['nullable', 'uuid'],
            'rooms.*.room_type_id' => ['required_without:rooms.*.id', 'nullable', 'uuid'],
            'rooms.*.quantity' => ['nullable', 'integer', 'min:1', "max:{$max}"],
            'rooms.*.room_id' => ['nullable', 'uuid'],
            'capacity_override' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['room_id.prohibited' => 'Use rooms[] instead.'];
    }
}
