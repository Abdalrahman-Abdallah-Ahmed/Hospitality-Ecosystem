<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class EventLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $trimmed = $this->hidesActorDetails();

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
            'context' => $trimmed && $this->context !== null ? Arr::except($this->context, 'ip') : $this->context,
            'evidence_level' => $this->evidence_level,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at,
            // The actor morphs; only a User has a resource of its own, so
            // anything else is handed back as the model serialises itself.
            'actor' => $this->whenLoaded('actor', function () use ($trimmed) {
                if ($trimmed) {
                    return $this->actor?->only(['id', 'name']);
                }

                return $this->actor instanceof User ? UserResource::make($this->actor) : $this->actor;
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * A hotel's staff see who at the platform acted, but not their contact
     * details or IP (spec 027 FR-019c). An actor who no longer exists counts: a
     * deleted super admin can't be told apart from a deleted hotel user. Stored
     * rows don't change. Only hotel users read this resource: a super admin's
     * own token never reaches the history route (spec 028).
     */
    private function hidesActorDetails(): bool
    {
        if ($this->actor_type !== (new User)->getMorphClass()) {
            return false;
        }

        return $this->actor === null || $this->actor->isSuperAdmin();
    }
}
