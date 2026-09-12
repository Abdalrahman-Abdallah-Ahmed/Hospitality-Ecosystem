<?php

namespace App\Ai\Tools;

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Enums\RoomTypes;
use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateRoomTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a room to this hotel. Use it when the admin describes a room that should exist in the system — a new room, or one that was never entered. Room numbers are unique within the hotel.';
    }

    public function handle(Request $request): Stringable|string
    {
        $roomNumber = $request->string('room_number')->trim()->toString();

        if ($roomNumber === '') {
            return 'A room number is required.';
        }

        // rooms has a unique index on (hotel_id, room_number). Checking first
        // turns a duplicate into a sentence the model can relay, instead of a
        // database exception that ends the conversation.
        $existing = Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('room_number', $roomNumber)
            ->first();

        if ($existing) {
            return "Room {$roomNumber} already exists in this hotel (room id: {$existing->id}). Nothing was created.";
        }

        $room = Room::create([
            'hotel_id' => $this->hotel->id,
            'room_number' => $roomNumber,
            'room_type' => $request->enum('room_type', RoomTypes::class, RoomTypes::DOUBLE),
            'floor' => $request->filled('floor') ? $request->integer('floor') : null,
            // `status` is a plain string column, unlike room_type and
            // housekeeping_status which are cast — so the backing value goes in.
            'status' => $request->enum('status', RoomStatusesEnum::class, RoomStatusesEnum::AVAILABLE)->value,
            'housekeeping_status' => HousekeepingStatusesEnum::CLEAN,
        ]);

        return "Room {$room->room_number} created (room id: {$room->id}), type {$room->room_type->value}, status {$room->status}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'room_number' => $schema->string()
                ->description('The room number, unique within this hotel (e.g. "203").')
                ->required(),
            'room_type' => $schema->string()
                ->enum(RoomTypes::class)
                ->description('The room type. Defaults to double if the admin does not say.')
                ->default(RoomTypes::DOUBLE->value),
            'floor' => $schema->integer()->description('Which floor the room is on, if mentioned.'),
            'status' => $schema->string()
                ->enum(RoomStatusesEnum::class)
                ->description('Availability status. Use maintenance only if the admin says the room is out of service.')
                ->default(RoomStatusesEnum::AVAILABLE->value),
        ];
    }
}
