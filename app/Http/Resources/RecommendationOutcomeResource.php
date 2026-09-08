<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendationOutcomeResource extends JsonResource
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
            'recommendation_id' => $this->recommendation_id,
            'booking_id' => $this->booking_id,
            'stay_id' => $this->stay_id,
            'outcome' => $this->outcome,
            'attribution_method' => $this->attribution_method,
            'evidence_level' => $this->evidence_level,
            'channel' => $this->channel,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'expected_value' => $this->expected_value,
            'currency' => $this->currency,
            'minutes_to_outcome' => $this->minutes_to_outcome,
            'decline_reason' => $this->decline_reason,
            'evidence_quote' => $this->evidence_quote,
            'confidence' => $this->confidence,
            'context' => $this->context,
            'type' => $this->type,
            'details' => $this->details,
            'occurred_at' => $this->occurred_at,
            'recommendation' => RecommendationResource::make($this->whenLoaded('recommendation')),
            'booking' => BookingResource::make($this->whenLoaded('booking')),
            'stay' => StayResource::make($this->whenLoaded('stay')),
            'recorded_by' => UserResource::make($this->whenLoaded('recordedBy')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
