<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLogResource extends JsonResource
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
            'event_type' => $this->event_type,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_kind' => $this->actor_kind,
            'changes' => $this->changes,
            'context' => $this->context,
            'evidence_level' => $this->evidence_level,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at,
            // The actor morphs; only a User has a resource of its own, so
            // anything else is handed back as the model serialises itself.
            'actor' => $this->whenLoaded(
                'actor',
                fn () => $this->actor instanceof User ? UserResource::make($this->actor) : $this->actor,
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
