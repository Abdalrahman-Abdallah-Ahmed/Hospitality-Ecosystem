<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationResource extends JsonResource
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
            'reservation_id' => $this->reservation_id,
            'activity_id' => $this->activity_id,
            'reason' => $this->reason,
            'predicted_confidence' => $this->predicted_confidence,
            'guest_confidence' => $this->guest_confidence,
            'priority' => $this->priority,
            'status' => $this->status,
            'recommended_at' => $this->recommended_at,
            'accepted_at' => $this->accepted_at,
            'rejected_at' => $this->rejected_at,
            'dismissed_at' => $this->dismissed_at,
            'evidence_level' => $this->evidence_level,
            'evidence_sources' => $this->evidence_sources,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'reservation' => ReservationResource::make($this->whenLoaded('reservation')),
            'activity' => ActivityResource::make($this->whenLoaded('activity')),
            'outcome' => RecommendationOutcomeResource::make($this->whenLoaded('outcome')),
            'bookings' => BookingResource::collection($this->whenLoaded('bookings')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
