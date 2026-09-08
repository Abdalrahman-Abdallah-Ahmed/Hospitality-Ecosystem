<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'task_categories' => TaskCategoryResource::collection($this->whenLoaded('taskCategories')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
