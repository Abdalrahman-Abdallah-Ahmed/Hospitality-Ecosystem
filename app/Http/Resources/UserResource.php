<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'role' => $this->role,
            'phone_number' => $this->phone_number,
            'team_id' => $this->team_id,
            'hotel_id' => $this->hotel_id,
            'hotel_group_id' => $this->hotel_group_id,
            'group_role' => $this->group_role,
            'staff_role_id' => $this->staff_role_id,
            'email_verified_at' => $this->email_verified_at,
            'staff_role' => StaffRoleResource::make($this->whenLoaded('staffRole')),
            // Only once staffRole is loaded, so a listing never lazy-loads a
            // role per user just to work out permissions.
            'permissions' => $this->when(
                $this->resource->relationLoaded('staffRole'),
                fn () => array_map(fn (Permission $permission) => $permission->value, $this->permissions()),
            ),
            'team' => TeamResource::make($this->whenLoaded('team')),
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
