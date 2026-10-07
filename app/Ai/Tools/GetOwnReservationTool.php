<?php

namespace App\Ai\Tools;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetOwnReservationTool implements Tool
{
    public function __construct(
        private readonly ?Reservation $reservation,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Retrieve the guest's own reservation — their current stay, next upcoming stay, or most recent past stay, whichever is relevant — including party composition, room types, each room's number and stay status, reservation value, and whether it is current (is_active). Never returns another guest's reservation.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'No reservation found for this guest.';
        }

        $reservation = $this->relevantReservation()
            ->load(['hotel', 'reservationRooms' => fn ($query) => $query->with(['roomType', 'room', 'stay'])]);

        // roomsForAi() reads the loaded lines; each gains its stay's status
        // (SPEC-007 FR-019), in the same order.
        $rooms = $reservation->roomsForAi();
        $rooms['rooms'] = $reservation->reservationRooms->values()->map(fn (ReservationRoom $line, int $i) => [
            ...$rooms['rooms'][$i],
            'stay_status' => $line->stay?->status?->value,
        ])->all();

        return json_encode([
            'id' => $reservation->id,
            'reservation_id' => $reservation->reservation_id,
            'status' => $reservation->status->value,
            'is_active' => $reservation->isActive(),
            'arrival_date' => $reservation->arrival_date->toDateString(),
            'departure_date' => $reservation->departure_date->toDateString(),
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            ...$rooms,
            'reservation_value' => $reservation->reservation_value,
            'currency' => $reservation->currency,
            'special_requests' => $reservation->special_requests,
        ]);
    }

    /**
     * The guest's live reservation when the one sender recognition picked is
     * not current: recognition ranks by date without looking at status, so it
     * can hand over a cancelled one while another is still booked. Same rule
     * as the request tools (ResolvesGuestStay), so `is_active` never tells the
     * Concierge to refuse what those tools would accept.
     */
    private function relevantReservation(): Reservation
    {
        if ($this->reservation->isActive()) {
            return $this->reservation;
        }

        $hotel = Hotel::withoutGlobalScopes()->find($this->reservation->hotel_id);
        $guest = Guest::withoutGlobalScopes()->find($this->reservation->guest_id);

        return ($hotel && $guest ? Reservation::activeFor($hotel, $guest) : null) ?? $this->reservation;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
