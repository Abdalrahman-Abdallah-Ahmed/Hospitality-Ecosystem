<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Services\Reservations\ReservationCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Puts physical rooms on a reservation's room lines, moves them, or takes
 * them off, through ReservationCommands::assignRooms, so the same room
 * assignment rules as the reservation screen apply (right type, free for the
 * nights, not out of order). All the assignments in one call happen
 * together or not at all (FR-013).
 */
class AssignRoomsTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Assign physical rooms to a reservation\'s room lines, change them, or remove them. For each assignment give '
            .'the room number (or null to remove the room from a line) and, when the reservation has several room types or '
            .'lines, the room type or the line id (from the reservation lookup). Only use room numbers the admin named. '
            .'If any assignment breaks a rule, none is made and the reason comes back.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $assignments = (array) ($request['assignments'] ?? []);

        if ($assignments === []) {
            return 'Say which rooms to assign.';
        }

        $lines = $reservation->reservationRooms()->active()->with(['roomType', 'room'])->get();
        $roomByLine = [];
        $summary = [];

        foreach ($assignments as $item) {
            $number = array_key_exists('room_number', (array) $item) && $item['room_number'] !== null ? trim((string) $item['room_number']) : null;
            $room = null;

            if ($number !== null) {
                $room = $this->findRoomByNumber($number);

                if (! $room) {
                    return "Room {$number} does not exist in this hotel. Nothing was changed.";
                }
            }

            $line = $this->lineFor($lines, $item, $room, $roomByLine);

            if (is_string($line)) {
                return $line;
            }

            $roomByLine[$line->id] = $room?->id;
            $summary[] = ['line_id' => $line->id, 'room_type' => $line->roomType?->name, 'room_number' => $room?->room_number];
        }

        $result = $this->attempt(fn () => app(ReservationCommands::class)->assignRooms($reservation, $roomByLine));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(['reservation_id' => $reservation->id, 'code' => $reservation->reservation_id], ['rooms' => $summary]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.')->required(),
            'assignments' => $schema->array()->items($schema->object([
                'room_number' => $schema->string()->description('The physical room to assign, or null to remove the room from the line.'),
                'room_type' => $schema->string()->description('Which line by room type, when the reservation has several types.'),
                'line_id' => $schema->string()->description('Exactly which line, from the reservation lookup.'),
            ]))->required(),
        ];
    }

    /**
     * The line an assignment is about: the one named by id, otherwise a line
     * of the room's (or the named) type, preferring one without a room and
     * never one already used in this call.
     *
     * @param  Collection<int, ReservationRoom>  $lines
     * @param  array<string, string|null>  $taken
     */
    private function lineFor($lines, mixed $item, mixed $room, array $taken): ReservationRoom|string
    {
        $item = (array) $item;
        $lineId = trim((string) ($item['line_id'] ?? ''));

        if ($lineId !== '') {
            if (array_key_exists($lineId, $taken)) {
                return 'The same room line is named twice; say which room it should have. Nothing was changed.';
            }

            return $lines->firstWhere('id', $lineId) ?? 'That line id is not a live room line of this reservation. Nothing was changed.';
        }

        $typeName = mb_strtolower(trim((string) ($item['room_type'] ?? '')));
        $typeId = $room?->room_type_id;

        $candidates = $lines
            ->reject(fn (ReservationRoom $line) => array_key_exists($line->id, $taken))
            ->filter(fn (ReservationRoom $line) => $typeName !== ''
                ? mb_strtolower((string) $line->roomType?->name) === $typeName
                : ($typeId === null || $line->room_type_id === $typeId));

        // Removing a room: only lines that have one.
        if ($room === null) {
            $candidates = $candidates->filter(fn (ReservationRoom $line) => $line->room_id !== null);
        }

        $line = $candidates->sortBy(fn (ReservationRoom $line) => $line->room_id === null ? 0 : 1)->first();

        if ($line) {
            return $line;
        }

        // A room of a type the reservation does not hold: hand it to the
        // assignment rules on a free line, so the refusal is theirs, word for
        // word, as staff would see it.
        if ($room && $typeName === '') {
            $line = $lines
                ->reject(fn (ReservationRoom $line) => array_key_exists($line->id, $taken))
                ->sortBy(fn (ReservationRoom $line) => $line->room_id === null ? 0 : 1)
                ->first();

            if ($line) {
                return $line;
            }
        }

        return $room
            ? "This reservation has no {$room->roomType?->name} room line left for room {$room->room_number}. Nothing was changed."
            : 'There is no matching room line to remove a room from. Nothing was changed.';
    }
}
