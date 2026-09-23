<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationRoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'room_type_id' => $this->room_type_id,
            'room_id' => $this->room_id,
            'status' => $this->status,
            // True on a line cancelled because its whole reservation was: the
            // lines un-cancelling the reservation brings back.
            'cancelled_with_reservation' => $this->cancelled_with_reservation,
            'room_type' => RoomTypeResource::make($this->whenLoaded('roomType')),
            'room' => RoomResource::make($this->whenLoaded('room')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
