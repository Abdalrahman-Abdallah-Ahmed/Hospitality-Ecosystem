<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Enums\ReservationRoomStatus;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Services\Reservations\ReservationCommands;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Cancels a reservation and every room on it, through the same path as the
 * reservation screen. Hard to reverse, so the admin confirms it first
 * (FR-017): the call pauses until their next message says yes. Nothing is
 * ever deleted (FR-019).
 */
class CancelReservationTool implements ConfirmsBeforeRunning, Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Cancel a reservation, found by its code, with every room on it. The system asks the admin to confirm '
            .'before it runs; just call it when the admin asks to cancel. Reservations are never deleted.';
    }

    public function needsConfirmation(Request $request): bool
    {
        return true;
    }

    public function confirmationSummary(Request $request, string $locale): string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return $locale === 'ar'
                ? 'إلغاء حجز غير موجود ('.$request->string('code')->toString().') — لن يتغير شيء.'
                : 'Cancel reservation '.$request->string('code')->toString().' (not found in this hotel; nothing will change).';
        }

        $reservation->load(['guest', 'reservationRooms.roomType', 'reservationRooms.room']);
        $rooms = $reservation->reservationRooms
            ->reject(fn (ReservationRoom $line) => $line->status === ReservationRoomStatus::CANCELLED)
            ->map(fn (ReservationRoom $line) => trim(($line->roomType?->name ?? '?').' '.($line->room?->room_number ?? ($locale === 'ar' ? 'غير محددة' : 'unassigned'))))
            ->implode(', ');
        $count = $reservation->reservationRooms->reject(fn (ReservationRoom $line) => $line->status === ReservationRoomStatus::CANCELLED)->count();
        $arrival = $reservation->arrival_date?->toDateString();
        $guest = $this->guestName($reservation->guest);

        return $locale === 'ar'
            ? "إلغاء الحجز {$reservation->reservation_id} باسم {$guest}: {$count} غرفة ({$rooms})، الوصول {$arrival}."
            : "Cancel reservation {$reservation->reservation_id} for {$guest}: {$count} room(s) ({$rooms}), arriving {$arrival}.";
    }

    public function handle(Request $request): Stringable|string
    {
        $reservation = $this->findReservationByCode($request->string('code')->toString());

        if (! $reservation) {
            return 'This hotel has no reservation with that code.';
        }

        $result = $this->attempt(fn () => app(ReservationCommands::class)->cancel($reservation));

        if (is_string($result)) {
            return $result;
        }

        return $this->done(
            ['reservation_id' => $result->id, 'code' => $result->reservation_id],
            ['status' => $result->status->value],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->description('The reservation code, e.g. RES-1001.')->required(),
        ];
    }
}
