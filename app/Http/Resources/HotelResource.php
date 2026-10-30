<?php

namespace App\Http\Resources;

use App\Support\Features;
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
            'housekeeping_team_id' => $this->housekeeping_team_id,
            'cleaning_task_category_id' => $this->cleaning_task_category_id,
            'inspection_task_category_id' => $this->inspection_task_category_id,
            'maintenance_team_id' => $this->maintenance_team_id,
            'maintenance_task_category_id' => $this->maintenance_task_category_id,
            'inspection_required' => (bool) $this->inspection_required,
            // Every unset value at its default, so the client always sees the
            // full settings (SPEC-073).
            'proactive_settings' => $this->resource->proactiveSettings()->toArray(),
            // Guest memories on or off for this hotel (SPEC-084 FR-032).
            'guest_memories_enabled' => (bool) ($this->guest_memories_enabled ?? true),
            // Platform-wide and read-only: the frontend hides the Transactions
            // menu from it (spec 019 FR-009).
            'transactions_enabled' => Features::enabled(Features::TRANSACTIONS),
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'hotel_group' => HotelGroupResource::make($this->whenLoaded('hotelGroup')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
