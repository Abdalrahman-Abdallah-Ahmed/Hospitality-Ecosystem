<?php

namespace App\Http\Resources;

use App\Enums\ReservationRoomStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
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
            // The property management system's own reference, not this row's id.
            'reservation_id' => $this->reservation_id,
            'arrival_date' => $this->arrival_date,
            'departure_date' => $this->departure_date,
            'status' => $this->status,
            'adults' => $this->adults,
            'children' => $this->children,
            'source' => $this->source,
            'special_requests' => $this->special_requests,
            'reservation_value' => $this->reservation_value,
            'currency' => $this->currency,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            // Every line, cancelled ones included, for history.
            'rooms' => ReservationRoomResource::collection($this->whenLoaded('reservationRooms')),
            'room_summary' => $this->whenLoaded('reservationRooms', fn () => $this->roomSummary()),
            'stay' => StayResource::make($this->whenLoaded('stay')),
            'recommendations' => RecommendationResource::collection($this->whenLoaded('recommendations')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Live lines counted per room type: "2 × Deluxe, 1 × Suite".
     *
     * @return array<int, array{room_type_id: string, room_type_name: ?string, quantity: int}>
     */
    private function roomSummary(): array
    {
        return $this->reservationRooms
            ->reject(fn ($line) => $line->status === ReservationRoomStatus::CANCELLED)
            ->groupBy('room_type_id')
            ->map(fn ($lines, $typeId) => [
                'room_type_id' => $typeId,
                'room_type_name' => $lines->first()->roomType?->name,
                'quantity' => $lines->count(),
            ])
            ->values()
            ->all();
    }
}
