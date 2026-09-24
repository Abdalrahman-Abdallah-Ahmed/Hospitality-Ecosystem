<?php

namespace App\Http\Resources;

use App\Enums\StayStatus;
use App\Models\Stay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StayResource extends JsonResource
{
    /**
     * The hotel day the front-desk flags are computed for. Set by the list
     * endpoints (a chosen day); left null elsewhere, where the flags are
     * computed for the stay's own hotel's today.
     */
    private ?string $day = null;

    /**
     * Stays for the front-desk lists, flagged against `$day`.
     *
     * @param  iterable<Stay>  $stays
     * @return list<self>
     */
    public static function forDay(iterable $stays, string $day): array
    {
        $resources = [];

        foreach ($stays as $stay) {
            $resource = new self($stay);
            $resource->day = $day;
            $resources[] = $resource;
        }

        return $resources;
    }

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
            'reservation_id' => $this->reservation_id,
            'reservation_room_id' => $this->reservation_room_id,
            'room_id' => $this->room_id,
            'planned_arrival_date' => $this->planned_arrival_date,
            'planned_departure_date' => $this->planned_departure_date,
            'checked_in_at' => $this->checked_in_at,
            'checked_out_at' => $this->checked_out_at,
            'status' => $this->status,
            'adults' => $this->adults,
            'children' => $this->children,
            'nights' => $this->nights,
            'room_revenue' => $this->room_revenue,
            'currency' => $this->currency,
            'market_segment' => $this->market_segment,
            'source_channel' => $this->source_channel,
            // Read-only: stamped when the guest messages the hotel during this stay.
            'first_contacted_at' => $this->first_contacted_at,
            'last_contacted_at' => $this->last_contacted_at,
            // Front-desk flags (SPEC-024 lists): arrived later than planned,
            // still expected after the planned departure, or still in the
            // house after it.
            'is_late' => $this->when($this->day !== null, fn () => $this->isExpected() && $this->planned_arrival_date->toDateString() < $this->day),
            'is_past_departure' => $this->when($this->day !== null, fn () => $this->isExpected() && $this->planned_departure_date->toDateString() <= $this->day),
            'is_overdue' => $this->when($this->day !== null, fn () => $this->status === StayStatus::IN_HOUSE && $this->planned_departure_date->toDateString() < $this->day),
            'room_type' => $this->whenLoaded('reservationRoom', fn () => $this->reservationRoom?->roomType
                ? ['id' => $this->reservationRoom->roomType->id, 'name' => $this->reservationRoom->roomType->name]
                : null),
            'guest' => GuestResource::make($this->whenLoaded('guest')),
            'reservation' => ReservationResource::make($this->whenLoaded('reservation')),
            'room' => RoomResource::make($this->whenLoaded('room')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function isExpected(): bool
    {
        return $this->status === StayStatus::EXPECTED;
    }
}
