<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
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
            'stay_id' => $this->stay_id,
            'room_id' => $this->room_id,
            'activity_id' => $this->activity_id,
            'item_name' => $this->item_name,
            'revenue_center' => $this->revenue_center,
            'department' => $this->department,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'discount_amount' => $this->discount_amount,
            'currency' => $this->currency,
            'transacted_at' => $this->transacted_at,
            'business_date' => $this->business_date,
            'seller_reference' => $this->seller_reference,
            'sold_by_user_id' => $this->sold_by_user_id,
            'source_system' => $this->source_system,
            'external_reference' => $this->external_reference,
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->booking_reference,
            'evidence_level' => $this->evidence_level,
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'raw_payload' => $this->raw_payload,
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            'stay' => StayResource::make($this->whenLoaded('stay')),
            'room' => RoomResource::make($this->whenLoaded('room')),
            'activity' => ActivityResource::make($this->whenLoaded('activity')),
            'booking' => BookingResource::make($this->whenLoaded('booking')),
            'sold_by' => UserResource::make($this->whenLoaded('soldBy')),
            'reverses' => TransactionResource::make($this->whenLoaded('reverses')),
            'reversals' => TransactionResource::collection($this->whenLoaded('reversals')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
