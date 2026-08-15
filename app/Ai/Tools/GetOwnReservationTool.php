<?php

namespace App\Ai\Tools;

use App\Models\Reservation;
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
        return "Retrieve the guest's own reservation — their current stay, next upcoming stay, or most recent past stay, whichever is relevant. Never returns another guest's reservation.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'No reservation found for this guest.';
        }

        return json_encode([
            'id' => $this->reservation->id,
            'reservation_id' => $this->reservation->reservation_id,
            'status' => $this->reservation->status->value,
            'arrival_date' => $this->reservation->arrival_date->toDateString(),
            'departure_date' => $this->reservation->departure_date->toDateString(),
            'adults' => $this->reservation->adults,
            'children' => $this->reservation->children,
            'room_number' => $this->reservation->room?->room_number,
            'special_requests' => $this->reservation->special_requests,
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
