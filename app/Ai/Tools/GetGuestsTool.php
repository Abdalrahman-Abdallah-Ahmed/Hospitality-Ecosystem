<?php

namespace App\Ai\Tools;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetGuestsTool implements Tool
{
    public function __construct(private readonly Hotel $hotel) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'This tool is used for getting the guests related to a hotel.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $currentConfirmed = function ($query) {
            $query->where('status', ReservationStatus::CONFIRMED)
                ->whereDate('departure_date', '>=', now()->toDateString());
        };

        // Only what the advisor needs to talk about a guest. Serialising the
        // whole model would also send email, phone number, identity
        // fingerprint and free-text preferences to the model provider.
        $guests = Guest::where('hotel_id', $this->hotel->id)
            ->whereHas('reservations', $currentConfirmed)
            ->with(['reservations' => $currentConfirmed])
            ->get()
            ->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'name' => trim($guest->first_name.' '.$guest->last_name),
                'is_vip' => (bool) $guest->is_vip,
                'loyalty_status' => $guest->loyalty_status,
                'preferred_language' => $guest->preferred_language,
                'reservations' => $guest->reservations->map(fn (Reservation $reservation) => [
                    'id' => $reservation->id,
                    'reservation_id' => $reservation->reservation_id,
                    'arrival_date' => $reservation->arrival_date?->toDateString(),
                    'departure_date' => $reservation->departure_date?->toDateString(),
                    'adults' => $reservation->adults,
                    'children' => $reservation->children,
                ])->values(),
            ])
            ->values();

        return json_encode($guests);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
