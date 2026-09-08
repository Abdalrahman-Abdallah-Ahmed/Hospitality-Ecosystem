<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HotelResource extends JsonResource
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
            'owner_id' => $this->owner_id,
            'hotel_group_id' => $this->hotel_group_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'address' => $this->address,
            'whatsapp_number' => $this->whatsapp_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'branding' => $this->branding,
            'ai_preferences' => $this->ai_preferences,
            'is_active' => $this->is_active,
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'hotel_group' => HotelGroupResource::make($this->whenLoaded('hotelGroup')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
