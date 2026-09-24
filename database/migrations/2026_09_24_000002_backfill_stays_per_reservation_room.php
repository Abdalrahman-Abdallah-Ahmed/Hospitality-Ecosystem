<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Moves stays from one per reservation to one per reservation line
 * (SPEC-023, research R22):
 *
 * 1. Each reservation's existing stay is linked to its first live line, or
 *    to its first line when every line is cancelled. The existing stay holds
 *    the reservation's room, check-in and value, which belong to a live line.
 * 2. Every other line gets a stay in the state the reservation implies:
 *    cancelled line or reservation → cancelled; checked in → in house (with
 *    the existing stay's check-in time); checked out → departed (with its
 *    times and nights); otherwise expected.
 * 3. A reservation with several live lines splits its value evenly across
 *    their stays (remainder cents on the first) and keeps its party on the
 *    first line's stay, so sums across stays still equal the reservation.
 *
 * The old one-stay-per-reservation unique index is dropped first, since the
 * lines' new stays share their reservation; the next migration adds the
 * per-line and per-room indexes.
 *
 * Tables are written directly, not through models, so tenant scopes and
 * audit events stay out of a one-off data move. A re-run changes nothing:
 * linked stays and lines that already have a stay are skipped, and the shares
 * come out the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropUnique(['reservation_id']);
        });

        DB::transaction(function () {
            DB::table('reservations')
                ->whereExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('reservation_rooms')
                    ->whereColumn('reservation_rooms.reservation_id', 'reservations.id'))
                ->select(['id', 'hotel_id', 'guest_id', 'arrival_date', 'departure_date', 'status', 'adults', 'children', 'reservation_value', 'currency', 'source'])
                ->chunkById(500, function ($reservations) {
                    foreach ($reservations as $reservation) {
                        $this->backfill($reservation);
                    }
                });
        });
    }

    /**
     * Deletes every stay but the earliest of each reservation (soft-deleted
     * ones included, since the restored unique index covers them), then unlinks
     * the rest from their lines. This also removes stays created after the
     * migration for a reservation's extra lines: a rollback returns to one
     * stay per reservation, which is what the older code expects.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM stays AS s
            WHERE s.reservation_id IS NOT NULL
              AND s.id <> (
                  SELECT s2.id FROM stays AS s2
                  WHERE s2.reservation_id = s.reservation_id
                  ORDER BY (s2.deleted_at IS NOT NULL), s2.created_at, s2.id
                  LIMIT 1
              )
            SQL);

        DB::table('stays')->update(['reservation_room_id' => null]);

        Schema::table('stays', function (Blueprint $table) {
            $table->unique('reservation_id');
        });
    }

    private function backfill(object $reservation): void
    {
        $lines = DB::table('reservation_rooms')
            ->where('reservation_id', $reservation->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $stays = DB::table('stays')
            ->where('reservation_id', $reservation->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $primary = $stays->first();
        $target = $lines->first(fn ($line) => $line->status !== 'cancelled') ?? $lines->first();

        if ($primary && $primary->reservation_room_id === null && ! $stays->contains('reservation_room_id', $target->id)) {
            DB::table('stays')->where('id', $primary->id)->update(['reservation_room_id' => $target->id]);
            $primary->reservation_room_id = $target->id;
        }

        $linked = $stays->pluck('reservation_room_id')->filter()->push($primary?->reservation_room_id)->filter()->all();
        $now = now();

        foreach ($lines as $line) {
            if (in_array($line->id, $linked, true)) {
                continue;
            }

            DB::table('stays')->insert([
                'id' => (string) Str::uuid(),
                'hotel_id' => $reservation->hotel_id,
                'guest_id' => $reservation->guest_id,
                'reservation_id' => $reservation->id,
                'reservation_room_id' => $line->id,
                'room_id' => $line->room_id,
                'planned_arrival_date' => $reservation->arrival_date,
                'planned_departure_date' => $reservation->departure_date,
                ...$this->stateFor($reservation, $line, $primary),
                'adults' => 0,
                'children' => 0,
                'room_revenue' => 0,
                'currency' => $reservation->currency ?? 'EUR',
                'source_channel' => $reservation->source,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->splitShares($reservation, $lines);
    }

    /**
     * @return array{status: string, checked_in_at: mixed, checked_out_at: mixed, nights: mixed}
     */
    private function stateFor(object $reservation, object $line, ?object $primary): array
    {
        $state = ['status' => 'expected', 'checked_in_at' => null, 'checked_out_at' => null, 'nights' => null];

        if ($line->status === 'cancelled' || $reservation->status === 'cancelled') {
            return ['status' => 'cancelled'] + $state;
        }

        return match ($reservation->status) {
            'checked_in' => ['status' => 'in_house', 'checked_in_at' => $primary?->checked_in_at] + $state,
            'checked_out' => [
                'status' => 'departed',
                'checked_in_at' => $primary?->checked_in_at,
                'checked_out_at' => $primary?->checked_out_at,
                'nights' => $primary?->nights,
            ],
            default => $state,
        };
    }

    /**
     * Even value split with the remainder cents on the first live line; the
     * party on the first live line's stay only. Skipped for one live line:
     * its stay already carries the whole reservation.
     */
    private function splitShares(object $reservation, $lines): void
    {
        $live = $lines->reject(fn ($line) => $line->status === 'cancelled')->values();

        if ($live->count() < 2) {
            return;
        }

        $totalCents = (int) round(((float) ($reservation->reservation_value ?? 0)) * 100);
        $baseCents = intdiv($totalCents, $live->count());
        $remainder = $totalCents - $baseCents * $live->count();

        foreach ($live as $index => $line) {
            $first = $index === 0;

            DB::table('stays')
                ->where('reservation_room_id', $line->id)
                ->whereNull('deleted_at')
                ->update([
                    'room_revenue' => ($baseCents + ($first ? $remainder : 0)) / 100,
                    'adults' => $first ? ($reservation->adults ?? 1) : 0,
                    'children' => $first ? ($reservation->children ?? 0) : 0,
                ]);
        }
    }
};
