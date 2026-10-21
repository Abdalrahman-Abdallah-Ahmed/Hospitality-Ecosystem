<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Ai\Tools\Concerns\FindsReservationStays;
use App\Ai\Tools\Contracts\WritesData;
use App\Enums\Permission;
use App\Enums\StayStatus;
use App\Models\Hotel;
use App\Models\Stay;
use App\Models\User;
use App\Services\NoShowService;
use App\Support\Audit\EventLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Marks guests who never arrived as no-shows, or undoes a mistaken one, for
 * the Admin AI through NoShowService, with the same rules and messages as the
 * front desk (SPEC-012 FR-013). Checks reservations.no_show for the admin it
 * acts for; writes are audited as the AI agent. Never an overbooking: an undo
 * that no longer fits is refused, and staff decide.
 *
 * Marking releases the rooms and records that the guest never came, so the
 * admin confirms it first (FR-017); undoing a mistake runs straight away.
 */
class NoShowTool implements ConfirmsBeforeRunning, Tool, WritesData
{
    use FindsReservationStays;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Mark a reservation as a no-show (the guest never arrived), or undo a mistaken no-show. Without '
            .'room_number or room_type it acts on the whole reservation; with room_number (or room_type for a room '
            .'with no number yet), on that one room only. A no-show can be marked from the arrival day, only for rooms that have not checked in, and releases the rooms. Only do '
            .'this when the admin asks; never decide on your own that a guest is a no-show.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return $request->string('action')->toString() !== 'undo';
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $code = trim($request->string('reservation_id')->toString());
        $reservation = $this->findReservation($this->hotel, $code);
        $number = trim($request->string('room_number')->toString());

        if (! $reservation) {
            return $locale === 'ar'
                ? "تسجيل عدم حضور للحجز {$code} (غير موجود في هذا الفندق) — لن يتغير شيء."
                : "Mark reservation {$code} as a no-show (not found in this hotel; nothing will change).";
        }

        $guest = trim(($reservation->guest?->first_name ?? '').' '.($reservation->guest?->last_name ?? '')) ?: ($locale === 'ar' ? 'ضيف' : 'guest');
        $type = trim($request->string('room_type')->toString());

        if ($number === '' && $type !== '') {
            return $locale === 'ar'
                ? "تسجيل غرفة واحدة من نوع {$type} من الحجز {$reservation->reservation_id} ({$guest}) كعدم حضور، وتحريرها."
                : "Mark one {$type} room of reservation {$reservation->reservation_id} ({$guest}) as a no-show and release it.";
        }

        if ($number !== '') {
            return $locale === 'ar'
                ? "تسجيل الغرفة {$number} من الحجز {$reservation->reservation_id} ({$guest}) كعدم حضور، وتحرير الغرفة."
                : "Mark room {$number} of reservation {$reservation->reservation_id} ({$guest}) as a no-show and release the room.";
        }

        return $locale === 'ar'
            ? "تسجيل الحجز {$reservation->reservation_id} ({$guest}) كعدم حضور، وتحرير كل غرفه."
            : "Mark reservation {$reservation->reservation_id} ({$guest}) as a no-show and release all of its rooms.";
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->user->hasPermission(Permission::RESERVATIONS_NO_SHOW)) {
            return 'You do not have permission to mark or undo no-shows.';
        }

        $action = $request->string('action')->toString();

        if (! in_array($action, ['mark', 'undo'], true)) {
            return 'Say whether to mark or undo the no-show.';
        }

        $reservation = $this->findReservation($this->hotel, trim($request->string('reservation_id')->toString()));

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $number = trim($request->string('room_number')->toString());
        $typeName = mb_strtolower(trim($request->string('room_type')->toString()));
        $live = $this->staysOf($reservation)->filter(fn (Stay $stay) => $stay->isLive());
        $stay = null;

        if ($number !== '') {
            $stay = $live->first(fn (Stay $stay) => $stay->reservationRoom?->room?->room_number === $number);

            if (! $stay) {
                return "Room {$number} is not on reservation {$reservation->reservation_id}.";
            }
        } elseif ($typeName !== '') {
            // A room with no number yet: the first of that type in the state
            // the action needs, unassigned ones first.
            $wanted = $action === 'mark' ? StayStatus::EXPECTED : StayStatus::NO_SHOW;
            $stay = $live
                ->filter(fn (Stay $stay) => $stay->status === $wanted && mb_strtolower((string) $stay->reservationRoom?->roomType?->name) === $typeName)
                ->sortBy(fn (Stay $stay) => $stay->reservationRoom?->room_id === null ? 0 : 1)
                ->first();

            if (! $stay) {
                return "Reservation {$reservation->reservation_id} has no {$typeName} room to ".($action === 'mark' ? 'mark as a no-show.' : 'restore.');
            }
        }

        $reason = $request->string('reason')->toString() ?: null;
        $noShows = app(NoShowService::class);

        try {
            $result = EventLogger::asAiAgent(fn () => match (true) {
                $action === 'mark' && $stay => $noShows->markStay($stay, $reason),
                $action === 'mark' => $noShows->markReservation($reservation, $reason),
                (bool) $stay => $noShows->undoStay($stay, $reason),
                default => $noShows->undoReservation($reservation, $reason),
            });
        } catch (ValidationException $e) {
            return $this->refusal($e);
        }

        $room = $stay ? ($stay->reservationRoom?->room?->room_number ?? 'unassigned '.$stay->reservationRoom?->roomType?->name) : null;
        $what = $room ? "Room {$room} of {$reservation->reservation_id}" : $reservation->reservation_id;
        $done = $action === 'mark' ? 'marked as a no-show' : 'no longer a no-show';
        $warnings = implode(' ', $result['warnings']);

        return "{$what} {$done}. Reservation status: {$result['reservation']->status->value}."
            .($warnings !== '' ? " Note: {$warnings}" : '');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['mark', 'undo'])
                ->description('mark: the guest never arrived. undo: reverse a no-show marked by mistake.')->required(),
            'reservation_id' => $schema->string()->description("The reservation's code, e.g. RES-1001.")->required(),
            'room_number' => $schema->string()
                ->description('Only this room of the reservation. Leave out to act on the whole reservation.'),
            'room_type' => $schema->string()
                ->description('For a room with no room number yet: only one room of this type, e.g. Deluxe. Leave out with room_number.'),
            'reason' => $schema->string()->description('Why, if the admin said, e.g. "flight cancelled".'),
        ];
    }
}
