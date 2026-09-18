<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A VIP guest as shown on the dashboard. The dashboard is open to every hotel
 * user, not just admins, so this carries what staff need to recognise and
 * look after the guest, never the contact details GuestResource gives admins.
 */
class VipGuestResource extends JsonResource
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'preferred_language' => $this->preferred_language,
            'stays' => StayResource::collection($this->whenLoaded('stays')),
        ];
    }
}
