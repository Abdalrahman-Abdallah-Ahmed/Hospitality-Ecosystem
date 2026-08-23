<?php

namespace App\Ai\Tools;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetGuestsTool implements Tool
{
    public function __construct(private readonly Hotel $hotel){}

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

        $guests = Guest::where('hotel_id', $this->hotel->id)
            ->whereHas('reservations', $currentConfirmed)
            ->with(['reservations' => $currentConfirmed])
            ->get();

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
