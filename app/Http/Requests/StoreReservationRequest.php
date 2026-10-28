<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericStoreRequest;
use App\Support\Reservations\ReservationRoomSync;

/**
 * The schema-derived reservation rules plus the shape of the room lines.
 * Whether a room type or room may be used, capacity and the other line rules
 * are checked by ReservationRoomSync, which the AI tool and the import share.
 */
class StoreReservationRequest extends GenericStoreRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = ReservationRoomSync::MAX_UNITS;
        $rules = parent::rules();

        return [
            ...$rules,
            // With `guests`, guest_id follows the primary entry, which may be
            // a new guest named by contact details (ReservationCreator::create()).
            'guest_id' => ['required_without:guests', ...array_filter($rules['guest_id'], fn ($rule) => $rule !== 'required')],
            'room_id' => ['prohibited'],
            'rooms' => ['required', 'array', 'min:1', "max:{$max}"],
            'rooms.*' => ['array'],
            'rooms.*.room_type_id' => ['required', 'uuid'],
            'rooms.*.quantity' => ['nullable', 'integer', 'min:1', "max:{$max}"],
            'rooms.*.room_id' => ['nullable', 'uuid'],
            'capacity_override' => ['sometimes', 'boolean'],
            'overbook_override' => ['sometimes', 'boolean'],
            'children_ages' => ['sometimes', 'array'],
            'children_ages.*' => ['integer', 'between:0,17'],
            'guests' => ['sometimes', 'array', 'min:1'],
            'guests.*' => ['array'],
            'guests.*.guest_id' => ['nullable', 'uuid', 'required_without_all:guests.*.phone_number,guests.*.email'],
            'guests.*.is_primary' => ['sometimes', 'boolean'],
            'guests.*.first_name' => ['nullable', 'string', 'max:255'],
            'guests.*.last_name' => ['nullable', 'string', 'max:255'],
            'guests.*.email' => ['nullable', 'email', 'max:255'],
            'guests.*.phone_number' => ['nullable', 'string', 'max:50'],
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
