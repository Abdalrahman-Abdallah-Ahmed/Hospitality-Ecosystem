<?php

use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayService;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function stayTestHotel(): Hotel
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Stay Test Hotel',
        'slug' => 'stay-test-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function stayTestGuest(Hotel $hotel): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);
}

it('creates exactly one stay per reservation, even when synced repeatedly', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => ReservationStatus::CONFIRMED->value,
    ]);

    // Simulates the same reservation being synced multiple times — e.g. a
    // repeat import restoring a soft-deleted reservation, or the update
    // endpoint being called more than once for the same row.
    ReservationCreator::syncStay($reservation);
    ReservationCreator::syncStay($reservation);
    ReservationCreator::syncStay($reservation);

    expect(Stay::where('reservation_id', $reservation->id)->count())->toBe(1);
});

it('automatically creates a stay for a newly created reservation', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => ReservationStatus::PENDING->value,
    ]);

    $stay = Stay::where('reservation_id', $reservation->id)->first();

    expect($stay)->not->toBeNull();
    expect($stay->status)->toBe(StayStatus::EXPECTED);
    expect($stay->planned_arrival_date->toDateString())->toBe('2026-09-01');
    expect($stay->planned_departure_date->toDateString())->toBe('2026-09-04');
});

it('does not mark a merely pending reservation as a no-show', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    // Arrival date is in the future — a pending reservation is "not yet
    // arrived", not "arrived and never checked in".
    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => now()->addMonth()->toDateString(),
        'departure_date' => now()->addMonth()->addDays(3)->toDateString(),
        'status' => ReservationStatus::PENDING->value,
    ]);

    expect(Stay::where('reservation_id', $reservation->id)->first()->status)->toBe(StayStatus::EXPECTED);
});

it('reverts a checked-in stay back to expected, clearing the stale check-in, when its reservation reverts to pending', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => ReservationStatus::CHECKED_IN->value,
    ]);

    $reservation->update(['status' => ReservationStatus::PENDING->value]);
    ReservationCreator::syncStay($reservation);

    $stay = Stay::where('reservation_id', $reservation->id)->first();

    expect($stay->status)->toBe(StayStatus::EXPECTED);
    expect($stay->checked_in_at)->toBeNull();
});

it('counts a departure-day guest as not occupying that night', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-05',
        'status' => StayStatus::IN_HOUSE,
    ]);

    // The night before departure: occupied.
    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2026-09-04')))->toBe(1);

    // Departure day itself: not occupied that night.
    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2026-09-05')))->toBe(0);

    // Before arrival: not occupied.
    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2026-08-31')))->toBe(0);
});

it('returns occupancy for a past date and a future date', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2020-01-01',
        'planned_departure_date' => '2020-01-05',
        'status' => StayStatus::DEPARTED,
    ]);

    Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2030-01-01',
        'planned_departure_date' => '2030-01-05',
        'status' => StayStatus::EXPECTED,
    ]);

    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2020-01-02')))->toBe(1);
    expect(Stay::occupiedRoomsOn($hotel, Carbon::parse('2030-01-02')))->toBe(0); // EXPECTED, not yet IN_HOUSE/DEPARTED
});

it('computes nights from actual dates when the guest checks out early', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $stay = Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-06', // planned 5 nights
    ]);

    $stayService = app(StayService::class);
    $stayService->checkIn($stay, Carbon::parse('2026-09-01 14:00'));
    $stayService->checkOut($stay, Carbon::parse('2026-09-04 10:00')); // left after 3 nights

    $stay->refresh();

    expect($stay->status)->toBe(StayStatus::DEPARTED);
    expect($stay->nights)->toBe(3);
    // The planned window is untouched — it's a separate fact from what happened.
    expect($stay->planned_departure_date->toDateString())->toBe('2026-09-06');
});

it('does not overwrite an existing check-in time on a repeated check-in call', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $stay = Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-04',
    ]);

    $stayService = app(StayService::class);
    $stayService->checkIn($stay, Carbon::parse('2026-09-01 14:00'));
    $stayService->checkIn($stay, Carbon::parse('2026-09-01 16:00'));

    expect($stay->fresh()->checked_in_at->toDateTimeString())->toBe('2026-09-01 14:00:00');
});

it('marks a stay cancelled when its reservation is cancelled', function () {
    $hotel = stayTestHotel();
    $guest = stayTestGuest($hotel);

    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => ReservationStatus::CONFIRMED->value,
    ]);

    ReservationCreator::syncStay($reservation);

    $reservation->update(['status' => ReservationStatus::CANCELLED->value]);
    ReservationCreator::syncStay($reservation);

    expect(Stay::where('reservation_id', $reservation->id)->first()->status)->toBe(StayStatus::CANCELLED);
});
