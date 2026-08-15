<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetRoomsTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieve the rooms for the current hotel, including room number, room type, floor, and status.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $rooms = Room::where('hotel_id', $this->hotel->id)
            ->orderBy('room_number')
            ->limit(100)
            ->get()
            ->map(fn (Room $room) => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->room_type,
                'floor' => $room->floor,
                'status' => $room->status,
            ])
            ->values();

        return json_encode($rooms);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
