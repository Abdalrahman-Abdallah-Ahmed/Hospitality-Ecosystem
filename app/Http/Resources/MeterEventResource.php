<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeterEventResource extends JsonResource
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
            'hotel_group_id' => $this->hotel_group_id,
            'hotel_id' => $this->hotel_id,
            'feature_code' => $this->feature_code,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_kind' => $this->actor_kind,
            'period_start' => $this->period_start,
            'occurred_at' => $this->occurred_at,
            'idempotency_key' => $this->idempotency_key,
            'metadata' => $this->metadata,
            'hotel_group' => HotelGroupResource::make($this->whenLoaded('hotelGroup')),
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
