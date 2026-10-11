<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thing the AI remembers about a guest, for the guest page's memory
 * panel (SPEC-084).
 */
class GuestMemoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stay = $this->sourceStay;

        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'content' => $this->content,
            'language' => $this->language,
            'confidence' => $this->confidence->value,
            'source_stay' => $stay ? [
                'id' => $stay->id,
                'arrival_date' => $stay->planned_arrival_date?->toDateString(),
                'departure_date' => $stay->planned_departure_date?->toDateString(),
            ] : null,
            'extracted_at' => $this->extracted_at?->toIso8601String(),
        ];
    }
}
