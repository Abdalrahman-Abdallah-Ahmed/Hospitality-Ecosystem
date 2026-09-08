<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StayResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'guest_id' => $this->guest_id,
            'reservation_id' => $this->reservation_id,
            'room_id' => $this->room_id,
            'planned_arrival_date' => $this->planned_arrival_date,
            'planned_departure_date' => $this->planned_departure_date,
            'checked_in_at' => $this->checked_in_at,
            'checked_out_at' => $this->checked_out_at,
            'status' => $this->status,
            'adults' => $this->adults,
            'children' => $this->children,
            'nights' => $this->nights,
            'room_revenue' => $this->room_revenue,
            'currency' => $this->currency,
            'market_segment' => $this->market_segment,
            'source_channel' => $this->source_channel,
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            'reservation' => ReservationResource::make($this->whenLoaded('reservation')),
            'room' => RoomResource::make($this->whenLoaded('room')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
