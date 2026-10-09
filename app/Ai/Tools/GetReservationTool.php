<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Models\Stay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One reservation in full for the Admin AI: guest, party, each room line
 * with its type, assigned room and stay. Use it to find the line ids room
 * assignment needs, and before changing a reservation.
 */
class GetReservationTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Look up one reservation by its code: guest, dates, party, status, and every room line with its room type, '
            .'assigned room number (null while unassigned), line id and stay status.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $reservation->load(['guest', 'reservationRooms.roomType', 'reservationRooms.room']);

        $stays = Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->get()
            ->keyBy('reservation_room_id');

        return json_encode([
            'id' => $reservation->id,
            'code' => $reservation->reservation_id,
            'status' => $reservation->status->value,
            'arrival_date' => $reservation->arrival_date?->toDateString(),
            'departure_date' => $reservation->departure_date?->toDateString(),
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'special_requests' => $reservation->special_requests,
            'guest' => $reservation->guest ? [
                'id' => $reservation->guest->id,
                'name' => $this->guestName($reservation->guest),
                'is_vip' => (bool) $reservation->guest->is_vip,
            ] : null,
            'rooms' => $reservation->reservationRooms->map(fn (ReservationRoom $line) => [
                'line_id' => $line->id,
                'room_type' => $line->roomType?->name,
                'room_number' => $line->room?->room_number,
                'line_status' => $line->status->value,
                'stay_status' => $stays->get($line->id)?->status?->value,
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.')->required(),
        ];
    }
}
