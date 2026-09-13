<?php

namespace App\Services;

use App\Enums\StayStatus;
use App\Models\Reservation;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns reservations (intentions — a booking that may be cancelled or a
 * no-show) into stays (facts — a person physically in a room from one date
 * to another). See Stay for why the two are kept separate.
 */
class StayService
{
    /**
     * Every reservation has exactly one stay, and the stay's planned side
     * follows the reservation. A moved room, changed dates, or a corrected
     * party size must reach the stay, or occupancy history and transaction
     * attribution keep reading the booking as it was first entered.
     *
     * Idempotent: calling it again returns the same stay (the unique index
     * on stays.reservation_id backs this). What actually happened — status,
     * check-in and check-out times, nights — is never touched here; that is
     * checkIn() and friends.
     */
    public function syncFromReservation(Reservation $reservation): Stay
    {
        $planned = [
            'guest_id' => $reservation->guest_id,
            'room_id' => $reservation->room_id,
            'planned_arrival_date' => $reservation->arrival_date,
            'planned_departure_date' => $reservation->departure_date,
            'adults' => $reservation->adults ?? 1,
            'children' => $reservation->children ?? 0,
            // A simple proxy for now: Phase 1 keeps one room per stay and
            // has no per-night rate breakdown, so the reservation's total
            // value stands in for room revenue until WP-3's ledger exists.
            'room_revenue' => $reservation->reservation_value ?? 0,
            'currency' => $reservation->currency ?? 'EUR',
            'source_channel' => $reservation->source,
        ];

        $stay = Stay::firstOrCreate(
            ['reservation_id' => $reservation->id],
            ['hotel_id' => $reservation->hotel_id, 'status' => StayStatus::EXPECTED, ...$planned],
        );

        // A no-op on the call that just created it; carries edits on every later one.
        $stay->update($planned);

        return $stay;
    }

    /**
     * Marks the guest as physically in the room. Keeps the earliest
     * checked_in_at if called more than once, rather than overwriting it —
     * check-in is a fact that happened once, not a value to keep resetting.
     */
    public function checkIn(Stay $stay, ?CarbonInterface $at = null): Stay
    {
        $stay->update([
            'status' => StayStatus::IN_HOUSE,
            'checked_in_at' => $stay->checked_in_at ?? ($at ?? Carbon::now()),
            'checked_out_at' => null,
            'nights' => null,
        ]);

        return $stay;
    }

    /**
     * Marks the guest as departed and computes nights from what actually
     * happened (checked_in_at → this checkout time), not from the planned
     * dates — a guest who leaves 2 nights early must not be billed/reported
     * as having stayed the full planned length.
     */
    public function checkOut(Stay $stay, ?CarbonInterface $at = null): Stay
    {
        $checkedOutAt = $at ?? Carbon::now();
        $checkedInAt = $stay->checked_in_at ?? $stay->planned_arrival_date;

        // Nights are counted by calendar date, not elapsed hours — a 14:00
        // check-in to a 10:00 checkout three calendar days later is 3
        // nights, not floor(2.83). diffInDays() on Carbon 3 also returns a
        // float, which the integer `nights` column would otherwise reject.
        $nights = (int) round(
            Carbon::parse($checkedInAt->toDateString())->diffInDays(Carbon::parse($checkedOutAt->toDateString()))
        );

        $stay->update([
            'status' => StayStatus::DEPARTED,
            'checked_out_at' => $checkedOutAt,
            'nights' => max(0, $nights),
        ]);

        return $stay;
    }

    /**
     * Guest never arrived. Distinct from CANCELLED (withdrawn ahead of time)
     * — a no-show is a stronger signal (a held room that earned nothing).
     */
    public function markNoShow(Stay $stay): Stay
    {
        $stay->update([
            'status' => StayStatus::NO_SHOW,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'nights' => null,
        ]);

        return $stay;
    }

    /**
     * Reservation was withdrawn before the guest arrived.
     */
    public function markCancelled(Stay $stay): Stay
    {
        $stay->update([
            'status' => StayStatus::CANCELLED,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'nights' => null,
        ]);

        return $stay;
    }

    /**
     * Reservation is confirmed but guest have not arrived yet.
     */
    public function markExpected(Stay $stay): Stay
    {
        $stay->update([
            'status' => StayStatus::EXPECTED,
            'checked_in_at' => null,
            'checked_out_at' => null,
            'nights' => null,
        ]);

        return $stay;
    }
}
