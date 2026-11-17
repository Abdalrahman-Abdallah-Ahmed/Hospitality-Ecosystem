<?php

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Writes a booking the way it was stored before SPEC-041: the local time a
 * guest gave saved as if it were UTC, and no local schedule.
 */
function abLegacyBooking($hotel, $activity, ?string $wallClock, array $attributes = []): string
{
    $id = (string) str()->uuid();

    DB::table('bookings')->insert([
        'id' => $id,
        'hotel_id' => $hotel->id,
        'guest_id' => abGuest($hotel)->id,
        'activity_id' => $activity?->id,
        'reference' => strtoupper(substr(str_replace('-', '', $id), 0, 8)),
        'item_name' => 'Legacy',
        'status' => 'confirmed',
        'scheduled_for' => $wallClock,
        'pax' => 2,
        'charge_model' => 'pay_on_site',
        'origin' => 'staff',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);

    return $id;
}

function abRunBackfill(): void
{
    (require database_path('migrations/2026_10_05_000003_backfill_booking_schedule_dates.php'))->up();
}

it('reads the stored wall clock as hotel-local and stores the real instant', function () {
    $hotel = avHotel('Europe/Berlin');
    $activity = abActivity($hotel, ['duration_days' => 2]);
    $id = abLegacyBooking($hotel, $activity, '2026-10-09 17:30:00');

    abRunBackfill();

    $row = DB::table('bookings')->find($id);

    expect($row->scheduled_date)->toBe('2026-10-09')
        ->and(substr($row->scheduled_time, 0, 5))->toBe('17:30')
        ->and($row->last_date)->toBe('2026-10-10')
        ->and($row->scheduled_for)->toBe('2026-10-09 15:30:00');
});

it('leaves a UTC hotel instant unchanged and fills the reservation from the stay', function () {
    [$hotel, $type, $rooms] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    $stay = fdStays($reservation)[0];
    $activity = abActivity($hotel);
    $id = abLegacyBooking($hotel, $activity, '2026-10-09 17:30:00', ['stay_id' => $stay->id]);

    abRunBackfill();

    $row = DB::table('bookings')->find($id);

    expect($row->scheduled_for)->toBe('2026-10-09 17:30:00')
        ->and($row->last_date)->toBe('2026-10-09')
        ->and($row->reservation_id)->toBe($reservation->id);
});

it('changes nothing when run twice', function () {
    $hotel = avHotel('Asia/Dubai');
    $id = abLegacyBooking($hotel, abActivity($hotel), '2026-10-09 08:00:00');

    abRunBackfill();
    $first = (array) DB::table('bookings')->find($id);

    abRunBackfill();
    $second = (array) DB::table('bookings')->find($id);

    expect($second)->toBe($first)
        ->and(Booking::withoutGlobalScope('hotel')->find($id)->scheduled_for->toDateTimeString())->toBe('2026-10-09 04:00:00');
});

it('leaves a booking without a time alone', function () {
    $hotel = avHotel();
    $id = abLegacyBooking($hotel, null, null);

    abRunBackfill();

    expect(DB::table('bookings')->find($id)->scheduled_date)->toBeNull();
});
