<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProactiveMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'status' => $this->status,
            // Why it was skipped, or why it is waiting.
            'reason' => $this->reason,
            'guest' => $this->whenLoaded('guest', fn () => [
                'id' => $this->guest->id,
                'name' => trim($this->guest->first_name.' '.$this->guest->last_name),
            ]),
            'reservation_id' => $this->reservation_id,
            'stay_id' => $this->stay_id,
            'booking_id' => $this->booking_id,
            'recommendation_id' => $this->recommendation_id,
            'due_at' => $this->due_at,
            'valid_until' => $this->valid_until,
            'sent_at' => $this->sent_at,
            'locale' => $this->locale,
            'body' => $this->body,
            'attempts' => $this->attempts,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
