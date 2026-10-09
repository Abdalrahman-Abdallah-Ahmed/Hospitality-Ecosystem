<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ListResult;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\HousekeepingService;
use Illuminate\Contracts\Database\Eloquent\Builder;
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
        return 'Retrieve the rooms for the current hotel, including room number, room type, floor, building, room status (available, occupied, or out_of_order, with the reason and expected end), housekeeping status (dirty, cleaning, clean, or inspected), and whether the room is ready for a guest. '
            .'Room status and housekeeping status are separate: report both. Filter by room type, floor, building, status, housekeeping status or room number. Returns at most 50 with the total.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $housekeeping = app(HousekeepingService::class);

        $query = Room::with('roomType')->where('hotel_id', $this->hotel->id)
            ->when($request->filled('room_type'), fn (Builder $query) => $query->whereHas('roomType', fn (Builder $type) => $type->whereRaw('lower(name) = ?', [mb_strtolower($request->string('room_type')->toString())])))
            ->when($request->filled('floor'), fn (Builder $query) => $query->where('floor', $request->string('floor')->toString()))
            ->when($request->filled('building'), fn (Builder $query) => $query->where('building', $request->string('building')->toString()))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('housekeeping_status'), fn (Builder $query) => $query->where('housekeeping_status', $request->string('housekeeping_status')->toString()))
            ->when($request->filled('room_number'), fn (Builder $query) => $query->where('room_number', $request->string('room_number')->toString()))
            ->orderBy('room_number');

        return ListResult::fromQuery($query, ListResult::limit($request), fn (Room $room) => [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'room_type' => $room->roomType?->name,
            'floor' => $room->floor,
            'building' => $room->building,
            'status' => $room->status,
            'out_of_order_reason' => $room->out_of_order_reason,
            'out_of_order_until' => $room->out_of_order_until?->toDateString(),
            'housekeeping_status' => $room->housekeeping_status,
            'ready' => $housekeeping->isReady($room, $this->hotel),
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'room_type' => $schema->string()->description('Only rooms of this type, by name, e.g. Deluxe.'),
            'floor' => $schema->string()->description('Only rooms on this floor.'),
            'building' => $schema->string()->description('Only rooms in this building or wing.'),
            'status' => $schema->string()->enum(['available', 'occupied', 'out_of_order']),
            'housekeeping_status' => $schema->string()->enum(['dirty', 'cleaning', 'clean', 'inspected']),
            'room_number' => $schema->string()->description('Just this room.'),
            'limit' => $schema->integer()->description('At most this many, 1-50. Default 50.'),
        ];
    }
}
