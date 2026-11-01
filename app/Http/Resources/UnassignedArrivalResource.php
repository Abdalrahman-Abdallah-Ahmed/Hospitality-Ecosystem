<?php

namespace App\Http\Resources;

use App\Models\ReservationRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reservation line still waiting for a physical room (spec 020,
 * contract §3). `is_late` is set by the controller: the arrival date has
 * passed and the guest has not come yet.
 *
 * @mixin ReservationRoom
 */
class UnassignedArrivalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservation = $this->reservation;
        $guest = $reservation->primaryGuest();

        return [
            'line_id' => $this->id,
            'reservation_id' => $reservation->id,
            'reservation_code' => $reservation->reservation_id,
            'reservation_status' => $reservation->status,
            'primary_guest' => $guest ? [
                'id' => $guest->id,
                'name' => trim($guest->first_name.' '.$guest->last_name),
            ] : null,
            'arrival_date' => $reservation->arrival_date->toDateString(),
            'departure_date' => $reservation->departure_date->toDateString(),
            'room_type' => [
                'id' => $this->roomType?->id,
                'name' => $this->roomType?->name,
            ],
            'is_late' => (bool) $this->is_late,
        ];
    }
}
