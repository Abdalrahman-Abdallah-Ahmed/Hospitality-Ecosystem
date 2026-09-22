<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'name' => $this->name,
            'description' => $this->description,
            'max_occupancy' => $this->max_occupancy,
            'adult_capacity' => $this->adult_capacity,
            'child_capacity' => $this->child_capacity,
            'bed_configuration' => $this->bed_configuration,
            'amenities' => $this->amenities,
            'base_price' => (string) $this->base_price,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
