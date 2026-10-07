<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the hotel-local schedule of existing bookings (research R4).
 *
 * Until now `scheduled_for` was parsed in the app timezone (UTC), so the time
 * a guest gave in hotel-local terms was stored as if it were UTC: the stored
 * wall clock *is* the local time. For each booking:
 *
 * - `scheduled_date` / `scheduled_time` take that wall clock;
 * - `scheduled_for` becomes the real instant, that wall clock in the hotel's
 *   timezone (unchanged for hotels on UTC);
 * - `last_date` covers the activity's `duration_days`;
 * - `reservation_id` comes from the stay, when there is one.
 *
 * Tables are written directly, so tenant scopes and audit events stay out of
 * a one-off data move. Only rows without a `scheduled_date` are touched, so a
 * re-run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('bookings')
                ->leftJoin('hotels', 'hotels.id', '=', 'bookings.hotel_id')
                ->leftJoin('activities', 'activities.id', '=', 'bookings.activity_id')
                ->leftJoin('stays', 'stays.id', '=', 'bookings.stay_id')
                ->whereNull('bookings.scheduled_date')
                ->where(fn ($query) => $query->whereNotNull('bookings.scheduled_for')->orWhereNotNull('bookings.stay_id'))
                ->select([
                    'bookings.id', 'bookings.scheduled_for', 'bookings.reservation_id',
                    'hotels.timezone', 'activities.duration_days', 'stays.reservation_id as stay_reservation_id',
                ])
                ->chunkById(500, function ($bookings) {
                    foreach ($bookings as $booking) {
                        DB::table('bookings')->where('id', $booking->id)->update($this->backfill($booking));
                    }
                }, 'bookings.id', 'id');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function backfill(object $booking): array
    {
        $values = ['reservation_id' => $booking->reservation_id ?? $booking->stay_reservation_id];

        if ($booking->scheduled_for === null) {
            return $values;
        }

        $local = CarbonImmutable::parse($booking->scheduled_for, 'UTC')
            ->shiftTimezone($booking->timezone ?: 'UTC');

        return [
            ...$values,
            'scheduled_date' => $local->toDateString(),
            'scheduled_time' => $local->format('H:i:s'),
            'last_date' => $local->addDays(max(1, (int) ($booking->duration_days ?? 1)) - 1)->toDateString(),
            'scheduled_for' => $local->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Only the derived columns are cleared: the original wall clock of a
     * non-UTC hotel is not restored, since the new value is the correct one.
     */
    public function down(): void
    {
        DB::table('bookings')->update([
            'scheduled_date' => null,
            'scheduled_time' => null,
            'last_date' => null,
        ]);
    }
};
