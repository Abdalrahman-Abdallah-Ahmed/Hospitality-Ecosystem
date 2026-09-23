<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericIndexRequest;

/**
 * The generic listing request plus two virtual filters, `room_type_id` and
 * `room_id`, which match a reservation when any of its live room lines does.
 * ReservationController applies them; they cannot be used to sort.
 */
class ReservationIndexRequest extends GenericIndexRequest
{
    public const LINE_FILTERS = ['room_type_id', 'room_id'];

    protected function filterColumns(): array
    {
        return array_values(array_unique([...parent::filterColumns(), ...self::LINE_FILTERS]));
    }
}
