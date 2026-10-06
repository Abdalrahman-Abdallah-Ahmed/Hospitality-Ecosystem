<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One guest request to cancel a booking, as the staff queue shows it.
 */
class BookingCancellationRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->booking;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->description,
            'resolution' => $this->resolution,
            'resolution_note' => $this->resolution_note,
            'resolved_by_user_id' => $this->resolved_by_user_id,
            'resolved_at' => $this->resolved_at,
            'created_at' => $this->created_at,
            'guest' => $this->whenLoaded('guest', fn () => [
                'id' => $this->guest?->id,
                'first_name' => $this->guest?->first_name,
                'last_name' => $this->guest?->last_name,
            ]),
            'booking' => $this->whenLoaded('booking', fn () => $booking ? [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'item_name' => $booking->item_name,
                'status' => $booking->status,
                'scheduled_date' => $booking->scheduled_date?->toDateString(),
                'scheduled_time' => $booking->scheduled_time !== null ? substr($booking->scheduled_time, 0, 5) : null,
                'pax' => $booking->pax,
                'activity' => $booking->activity ? ['id' => $booking->activity->id, 'name' => $booking->activity->name] : null,
            ] : null),
        ];
    }
}
