<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\FindsReservationStays;
use App\Enums\Permission;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayLifecycleService;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Checks guests in for the Admin AI through StayLifecycleService, with the
 * same rules and messages as the front desk (FR-023). Checks stays.check_in
 * for the admin it acts for; writes are audited as the AI agent.
 */
class CheckInTool implements Tool
{
    use FindsReservationStays;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Check guests in for a reservation: every room waiting to check in, or only the rooms with the given '
            .'room numbers. For a room with no physical room assigned yet, name the room to put the guest in with '
            .'"assign" (room type and room number). The reservation must be confirmed, arriving today or earlier, and '
            .'each room free and in order; if not, nothing is checked in and the reasons come back.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->hasPermission(Permission::STAYS_CHECK_IN)) {
            return 'You do not have permission to check guests in.';
        }

        $reservation = $this->findReservation($this->hotel, trim($request->string('reservation_id')->toString()));

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $stays = $this->staysOf($reservation);
        $roomsByStayId = [];

        foreach ((array) ($request['assign'] ?? []) as $item) {
            $number = trim((string) ($item['room_number'] ?? ''));
            $typeName = mb_strtolower(trim((string) ($item['room_type'] ?? '')));
            $room = Room::withoutGlobalScope('hotel')->where('hotel_id', $this->hotel->id)->where('room_number', $number)->first();

            if (! $room) {
                return "Room {$number} does not exist in this hotel.";
            }

            $stay = $stays->first(fn (Stay $stay) => $stay->status === StayStatus::EXPECTED
                && $stay->reservationRoom?->room_id === null
                && ! isset($roomsByStayId[$stay->id])
                && ($typeName === '' || mb_strtolower((string) $stay->reservationRoom?->roomType?->name) === $typeName));

            if (! $stay) {
                return "There is no unassigned room waiting to check in on this reservation to put room {$number} on.";
            }

            $roomsByStayId[$stay->id] = $room->id;
        }

        $numbers = $this->roomNumbers((array) ($request['room_numbers'] ?? []));
        $at = $request->string('checked_in_at')->toString() ?: null;
        $lifecycle = app(StayLifecycleService::class);

        try {
            $result = EventLogger::asAiAgent(function () use ($lifecycle, $reservation, $stays, $roomsByStayId, $numbers, $at) {
                if ($numbers === []) {
                    return $lifecycle->checkInReservation($reservation, $roomsByStayId, $at);
                }

                $chosen = $stays->filter(fn (Stay $stay) => in_array($stay->reservationRoom?->room?->room_number, $numbers, true) || isset($roomsByStayId[$stay->id]));

                if ($chosen->isEmpty()) {
                    throw ValidationException::withMessages(['room_numbers' => 'None of those rooms is on this reservation.']);
                }

                // One transaction, so the rooms named go in together or not at all.
                return DB::transaction(function () use ($lifecycle, $chosen, $roomsByStayId, $at) {
                    $warnings = [];

                    foreach ($chosen as $stay) {
                        $result = $lifecycle->checkIn($stay, $roomsByStayId[$stay->id] ?? null, $at);
                        $warnings = [...$warnings, ...$result['warnings']];
                    }

                    return [...$result, 'warnings' => $warnings];
                });
            });
        } catch (ValidationException $e) {
            return $this->refusal($e);
        }

        $rooms = $this->staysOf($reservation)
            ->where('status', StayStatus::IN_HOUSE)
            ->map(fn (Stay $stay) => $stay->room?->room_number)
            ->filter()
            ->implode(', ');
        $warnings = collect($result['warnings'])->pluck('message')->implode(' ');

        return "Checked in {$reservation->reservation_id}. Rooms in the house: {$rooms}. Reservation status: {$result['reservation']->status->value}."
            .($warnings !== '' ? " Note: {$warnings}" : '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reservation_id' => $schema->string()->description("The reservation's code, e.g. RES-1001.")->required(),
            'room_numbers' => $schema->array()->items($schema->string())
                ->description('Only check in these rooms (by room number). Leave out to check in every room waiting.'),
            'assign' => $schema->array()
                ->items($schema->object([
                    'room_type' => $schema->string()->description('The room type of the unassigned room, e.g. Deluxe.'),
                    'room_number' => $schema->string()->description('The physical room to put the guest in.')->required(),
                ]))
                ->description('Rooms to assign to unassigned lines as part of the check-in. Only when the admin names the room.'),
            'checked_in_at' => $schema->string()
                ->description('The actual check-in time if it was earlier today (ISO 8601). Only when the admin states it.'),
        ];
    }
}
