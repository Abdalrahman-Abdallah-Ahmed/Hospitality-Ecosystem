<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'room_id' => $this->room_id,
            'reservation_id' => $this->reservation_id,
            'guest_id' => $this->guest_id,
            'assigned_to_team_id' => $this->assigned_to_team_id,
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'task_category_id' => $this->task_category_id,
            'created_by_user_id' => $this->created_by_user_id,
            'title' => $this->title,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'room' => RoomResource::make($this->whenLoaded('room')),
            'reservation' => ReservationResource::make($this->whenLoaded('reservation')),
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            'assigned_to_team' => TeamResource::make($this->whenLoaded('assignedToTeam')),
            'assigned_to_user' => UserResource::make($this->whenLoaded('assignedToUser')),
            'task_category' => TaskCategoryResource::make($this->whenLoaded('taskCategory')),
            'created_by_user' => UserResource::make($this->whenLoaded('createdByUser')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
