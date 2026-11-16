<?php

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function legacyReservationForGuest($hotel, string $code): array
{
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '+'.random_int(1000000000, 9999999999),
    ]);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => $code,
        'arrival_date' => '2026-11-01',
        'departure_date' => '2026-11-04',
        'adults' => 2,
        'children' => 1,
        'children_ages' => [],
    ]);

    return [$reservation, $guest];
}

it('backfills live and soft-deleted reservations idempotently without changing legacy party data', function () {
    [, $hotel] = adminWithHotel();
    [$active, $activeGuest] = legacyReservationForGuest($hotel, 'MIG-ACTIVE');
    [$deleted, $deletedGuest] = legacyReservationForGuest($hotel, 'MIG-DELETED');
    $deleted->delete();

    $before = [$active->fresh()->guest_id, $active->fresh()->children, $active->fresh()->children_ages];

    $migration = require database_path('migrations/2026_10_14_000003_backfill_reservation_guests.php');
    $migration->up();
    $migration->up();
    $migration->down();

    expect(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $active->id)->count())->toBe(1)
        ->and(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $active->id)->sole()->guest_id)->toBe($activeGuest->id)
        ->and(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $active->id)->sole()->is_primary)->toBeTrue()
        ->and(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $deleted->id)->count())->toBe(1)
        ->and(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $deleted->id)->sole()->guest_id)->toBe($deletedGuest->id)
        ->and(ReservationGuest::withoutGlobalScope('hotel')->where('reservation_id', $active->id)->exists())->toBeTrue()
        ->and([$active->fresh()->guest_id, $active->fresh()->children, $active->fresh()->children_ages])->toBe($before);
});
