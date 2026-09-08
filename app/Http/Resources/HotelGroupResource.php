<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HotelGroupResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'country_code' => $this->country_code,
            'default_currency' => $this->default_currency,
            'default_timezone' => $this->default_timezone,
            'settings' => $this->settings,
            'is_active' => $this->is_active,
            'hotels' => HotelResource::collection($this->whenLoaded('hotels')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
