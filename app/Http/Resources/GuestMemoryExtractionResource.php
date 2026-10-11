<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guest memory extraction, for the super-admin operator view (SPEC-084).
 * Identifiers and outcome only: never a memory's words.
 */
class GuestMemoryExtractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'guest_id' => $this->guest_id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'retry_at' => $this->retry_at?->toIso8601String(),
            'memories_written' => $this->memories_written,
            'ai_run_id' => $this->ai_run_id,
            'last_error' => $this->last_error,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
