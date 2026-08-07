<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetReservationsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Retrieve today's reservations (arriving today) for the current hotel, including guest name, room number, and status.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $reservations = Reservation::with(['guest', 'room'])
            ->where('hotel_id', $this->hotel->id)
            ->whereDate('arrival_date', now()->toDateString())
            ->get()
            ->map(fn (Reservation $reservation) => [
                'id' => $reservation->id,
                'reservation_id' => $reservation->reservation_id,
                'guest_name' => trim($reservation->guest->first_name.' '.$reservation->guest->last_name),
                'room_number' => $reservation->room?->room_number,
                'status' => $reservation->status->value,
                'adults' => $reservation->adults,
                'children' => $reservation->children,
            ])
            ->values();

        return json_encode($reservations);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
