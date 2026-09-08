<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestResource extends JsonResource
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'preferred_language' => $this->preferred_language,
            'nationality' => $this->nationality,
            'preferences' => $this->preferences,
            'loyalty_status' => $this->loyalty_status,
            'marketing_consent' => $this->marketing_consent,
            'external_id' => $this->external_id,
            'channel' => $this->channel,
            // identity_hash itself stays internal — only whether identity
            // resolution has run is of any use to a client.
            'identity_resolved_at' => $this->identity_resolved_at,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'reservations' => ReservationResource::collection($this->whenLoaded('reservations')),
            'stays' => StayResource::collection($this->whenLoaded('stays')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            // Conversations belong to the AI package, which has no resource
            // of its own; they serialise as the package models define.
            'conversations' => $this->whenLoaded('conversations'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
