<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsageCounterResource extends JsonResource
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
            'feature_code' => $this->feature_code,
            'period_start' => $this->period_start,
            'used' => $this->used,
            'recomputed_at' => $this->recomputed_at,
            'hotel_group' => HotelGroupResource::make($this->whenLoaded('hotelGroup')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
