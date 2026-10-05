<?php

namespace App\Http\Resources;

use App\Models\Hotel;
use App\Services\HousekeepingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hotel = $this->hotelForReadiness();

        return [
            'id' => $this->id,
            'hotel_id' => $this->hotel_id,
            'room_number' => $this->room_number,
            'room_type_id' => $this->room_type_id,
            'room_type' => RoomTypeResource::make($this->whenLoaded('roomType')),
            'floor' => $this->floor,
            'building' => $this->building,
            'status' => $this->status,
            'housekeeping_status' => $this->housekeeping_status,
            'housekeeping_status_changed_at' => $this->housekeeping_status_changed_at,
            'ready' => $hotel ? app(HousekeepingService::class)->isReady($this->resource, $hotel) : null,
            'out_of_order' => $this->isOutOfOrder() ? [
                'reason' => $this->out_of_order_reason,
                'since' => $this->out_of_order_since,
                'expected_end_date' => $this->out_of_order_until?->toDateString(),
                'overdue' => $this->out_of_order_until !== null
                    && $this->out_of_order_until->toDateString() < CarbonImmutable::now($hotel?->timezone ?? config('app.timezone'))->toDateString(),
                'by_user_id' => $this->out_of_order_by_user_id,
                'task_id' => $this->out_of_order_task_id,
            ] : null,
            'hotel' => HotelResource::make($this->whenLoaded('hotel')),
            'reservations' => ReservationResource::collection($this->whenLoaded('reservations')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The room's hotel, for readiness. The room endpoints and the board hand
     * it over (Room::withReadiness() / $readinessHotel); a room nested in a
     * stay, task or reservation has none and reports `ready` as null rather
     * than reading the hotel once per row.
     */
    private function hotelForReadiness(): ?Hotel
    {
        return $this->resource->readinessHotel
            ?? ($this->relationLoaded('hotel') ? $this->hotel : null);
    }
}
