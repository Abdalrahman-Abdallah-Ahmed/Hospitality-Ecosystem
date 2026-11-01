<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Contracts\WritesData;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Stay;
use App\Services\Reservations\RoomAssignmentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Moves a guest who is in the house to another room for the Admin AI,
 * through RoomAssignmentService, with the same rules and messages as the
 * front desk (spec 020 FR-023): a reason, a free room that is not out of
 * order, the line's room type unless the admin asked for an upgrade. Never
 * overbooks. The old room is left dirty with a cleaning task; the guest is
 * not messaged.
 */
class MoveRoomTool implements Tool, WritesData
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Move a guest who is already checked in to another room, for the rest of their stay. Give the reservation code, '
            .'the room number to move them to, and the reason the admin gave. When several rooms of the reservation are '
            .'checked in, also give the room they are in now. Only use room numbers the admin named; set upgrade only when '
            .'the admin asked to give the guest a room of another type. The guest is not messaged: tell the admin to let them know.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $inHouse = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->where('status', StayStatus::IN_HOUSE)
            ->with('room')
            ->get();

        $current = trim($request->string('current_room_number')->toString());
        $stay = $current !== ''
            ? $inHouse->first(fn (Stay $stay) => $stay->room?->room_number === $current)
            : ($inHouse->count() === 1 ? $inHouse->first() : null);

        if (! $stay) {
            return match (true) {
                $inHouse->isEmpty() => "No guest of reservation {$reservation->reservation_id} is checked in; assign rooms before arrival instead.",
                $current !== '' => "No guest of reservation {$reservation->reservation_id} is checked in to room {$current}.",
                default => 'Several rooms of this reservation are checked in; say which room the guest is in now.',
            };
        }

        $number = trim($request->string('room_number')->toString());
        $room = $this->findRoomByNumber($number);

        if (! $room) {
            return "Room {$number} does not exist in this hotel. Nothing was changed.";
        }

        $reason = trim($request->string('reason')->toString());

        if ($reason === '') {
            return 'Say why the guest is moving; a room move needs a reason.';
        }

        $result = $this->attempt(fn () => app(RoomAssignmentService::class)->move($stay, $room, $reason, $request->boolean('upgrade')));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['reservation_id' => $reservation->id, 'code' => $reservation->reservation_id, 'stay_id' => $stay->id],
            ['from_room' => $result['vacated_room']->room_number, 'to_room' => $room->room_number],
            'The old room needs cleaning. The guest was not messaged.',
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.')->required(),
            'room_number' => $schema->string()->description('The room to move the guest to.')->required(),
            'reason' => $schema->string()->description('Why the guest is moving, as the admin said.')->required(),
            'current_room_number' => $schema->string()->description('The room the guest is in now, when several rooms of the reservation are checked in.'),
            'upgrade' => $schema->boolean()->description('True only when the admin explicitly asked to give the guest a room of another type.'),
        ];
    }
}
