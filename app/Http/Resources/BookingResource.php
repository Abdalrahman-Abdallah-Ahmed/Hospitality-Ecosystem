<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
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
            'guest_id' => $this->guest_id,
            'stay_id' => $this->stay_id,
            'activity_id' => $this->activity_id,
            'recommendation_id' => $this->recommendation_id,
            'reference' => $this->reference,
            'item_name' => $this->item_name,
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for,
            'pax' => $this->pax,
            'charge_model' => $this->charge_model,
            'expected_value' => $this->expected_value,
            'currency' => $this->currency,
            'origin' => $this->origin,
            'channel' => $this->channel,
            'created_by_user_id' => $this->created_by_user_id,
            'confirmed_at' => $this->confirmed_at,
            'realised_at' => $this->realised_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'evidence_level' => $this->evidence_level,
            'context' => $this->context,
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            'stay' => StayResource::make($this->whenLoaded('stay')),
            'activity' => ActivityResource::make($this->whenLoaded('activity')),
            'recommendation' => RecommendationResource::make($this->whenLoaded('recommendation')),
            'created_by' => UserResource::make($this->whenLoaded('createdBy')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'outcome' => RecommendationOutcomeResource::make($this->whenLoaded('outcomeLink')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
