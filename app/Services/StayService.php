<?php

namespace App\Services;

use App\Enums\ReservationRoomStatus;
use App\Enums\StayStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns reservations (intentions — a booking that may be cancelled or a
 * no-show) into stays (facts — a person physically in a room from one date
 * to another). See Stay for why the two are kept separate.
 */
class StayService
{
    /**
     * Keeps one stay per reservation line (SPEC-023) and each stay's planned
     * side in step with its reservation and line. A moved room, changed dates
     * or a corrected party must reach the stays, or occupancy history and
     * transaction attribution keep reading the booking as it was first entered.
     *
     * - Every live line gets its stay, created expected. A cancelled line keeps
     *   the stay it had, so bringing it back finds its history.
     * - Expected and in-house stays copy the whole planned side (a room move,
     *   an extension, a corrected party); their check-in time is never
     *   touched here. A departed stay is history and is never touched.
     * - A cancelled line's expected stay is cancelled; a live line's cancelled
     *   stay is expected again.
     * - The reservation's value is split evenly across its live lines
     *   (remainder cents on the first) and its party sits on the first live
     *   line's stay, so sums across stays equal the reservation (D8).
     *
     * Idempotent. What actually happened — check-in, check-out, nights — is
     * StayLifecycleService's job, never this method's.
     *
     * @return Collection<int, Stay> the stays of the reservation's lines, in line order
     */
    public function syncForReservation(Reservation $reservation): Collection
    {
        $lines = ReservationRoom::withoutGlobalScope('hotel')
            ->where('reservation_id', $reservation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return collect([$this->syncLineless($reservation)]);
        }

        $live = $lines->reject(fn (ReservationRoom $line) => $line->status === ReservationRoomStatus::CANCELLED)->values();
        $shares = $this->revenueShares((float) ($reservation->reservation_value ?? 0), $live->count());
        $firstLiveId = $live->first()?->id;

        return $lines->map(function (ReservationRoom $line) use ($reservation, $live, $shares, $firstLiveId) {
            $isLive = $line->status !== ReservationRoomStatus::CANCELLED;
            $liveIndex = $isLive ? $live->search(fn (ReservationRoom $l) => $l->id === $line->id) : null;
            $isFirst = $line->id === $firstLiveId;

            $planned = [
                'guest_id' => $reservation->guest_id,
                'reservation_id' => $reservation->id,
                'room_id' => $line->room_id,
                'planned_arrival_date' => $reservation->arrival_date,
                'planned_departure_date' => $reservation->departure_date,
                'adults' => $isFirst ? ($reservation->adults ?? 1) : 0,
                'children' => $isFirst ? ($reservation->children ?? 0) : 0,
                'room_revenue' => $liveIndex === null ? 0 : $shares[$liveIndex],
                'currency' => $reservation->currency ?? 'EUR',
                'source_channel' => $reservation->source,
            ];

            $stay = Stay::withoutGlobalScope('hotel')->where('reservation_room_id', $line->id)->first();

            if (! $stay) {
                // A line cancelled before it ever had a stay (a replaced line
                // of a restored reservation) needs no history row.
                return $isLive
                    ? Stay::create(['hotel_id' => $reservation->hotel_id, 'reservation_room_id' => $line->id, 'status' => StayStatus::EXPECTED, ...$planned])
                    : null;
            }

            match ($stay->status) {
                StayStatus::EXPECTED => $stay->update($isLive ? $planned : ['status' => StayStatus::CANCELLED]),
                StayStatus::CANCELLED => $isLive ? $stay->update(['status' => StayStatus::EXPECTED, ...$planned]) : null,
                StayStatus::IN_HOUSE => $stay->update([...$planned, 'room_id' => $line->room_id ?? $stay->room_id]),
                default => null,
            };

            return $stay;
        })->filter()->values();
    }

    /**
     * A reservation with no lines at all — only legacy rows written outside
     * ReservationCreator are in that state — keeps the single stay it always
     * had, with no line and no room.
     */
    private function syncLineless(Reservation $reservation): Stay
    {
        $planned = [
            'guest_id' => $reservation->guest_id,
            'planned_arrival_date' => $reservation->arrival_date,
            'planned_departure_date' => $reservation->departure_date,
            'adults' => $reservation->adults ?? 1,
            'children' => $reservation->children ?? 0,
            'room_revenue' => $reservation->reservation_value ?? 0,
            'currency' => $reservation->currency ?? 'EUR',
            'source_channel' => $reservation->source,
        ];

        $stay = Stay::withoutGlobalScope('hotel')->firstOrCreate(
            ['reservation_id' => $reservation->id, 'reservation_room_id' => null],
            ['hotel_id' => $reservation->hotel_id, 'status' => StayStatus::EXPECTED, ...$planned],
        );

        if ($stay->status === StayStatus::EXPECTED) {
            $stay->update($planned);
        }

        return $stay;
    }

    /**
     * An even split of `$total` over `$count` lines, in whole cents, with the
     * remainder on the first line.
     *
     * @return list<float>
     */
    private function revenueShares(float $total, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $cents = (int) round($total * 100);
        $base = intdiv($cents, $count);
        $shares = array_fill(0, $count, $base / 100);
        $shares[0] = ($base + $cents - $base * $count) / 100;

        return $shares;
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
    public function checkOut(Stay $stay, ?CarbonInterface $at = null, ?string $timezone = null): Stay
    {
        $checkedOutAt = $at ?? Carbon::now();
        $checkedInAt = $stay->checked_in_at ?? $stay->planned_arrival_date;

        // Calendar dates as the hotel sees them: a 23:30 check-in is that
        // day's night, whatever the server's timezone says.
        $localDate = fn (CarbonInterface $moment) => $timezone
            ? $moment->copy()->setTimezone($timezone)->toDateString()
            : $moment->toDateString();

        // Nights are counted by calendar date, not elapsed hours — a 14:00
        // check-in to a 10:00 checkout three calendar days later is 3
        // nights, not floor(2.83). diffInDays() on Carbon 3 also returns a
        // float, which the integer `nights` column would otherwise reject.
        $nights = (int) round(
            Carbon::parse($stay->checked_in_at ? $localDate($checkedInAt) : $checkedInAt->toDateString())
                ->diffInDays(Carbon::parse($localDate($checkedOutAt)))
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
