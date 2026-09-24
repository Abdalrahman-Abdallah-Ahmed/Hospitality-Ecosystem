<?php

use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\StayService;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
| One stay per reservation line (SPEC-023): created, kept in step, cancelled
| and brought back with its line.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @return array{Hotel, RoomType, Guest}
 */
function syncFixture(): array
{
    $hotel = avHotel();
    $type = avType($hotel, 'Deluxe');
    avRooms($hotel, $type, 5);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(6), 'channel' => 'booking_com']);

    return [$hotel, $type, $guest];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function syncBook(Hotel $hotel, RoomType $type, Guest $guest, int $units = 2, array $attributes = []): Reservation
{
    return ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => now()->addDays(10)->toDateString(),
        'departure_date' => now()->addDays(13)->toDateString(),
        'status' => 'confirmed',
        'adults' => 2,
        'reservation_value' => 300,
        ...$attributes,
    ], [['room_type_id' => $type->id, 'quantity' => $units]]);
}

function stayOf(string $lineId): Stay
{
    return Stay::where('reservation_room_id', $lineId)->sole();
}

it('creates one expected stay per line, linked to it', function () {
    [$hotel, $type, $guest] = syncFixture();

    $reservation = syncBook($hotel, $type, $guest);

    $lines = $reservation->reservationRooms()->get();
    expect($reservation->stays()->count())->toBe(2);

    foreach ($lines as $line) {
        expect(stayOf($line->id)->status)->toBe(StayStatus::EXPECTED)
            ->and(stayOf($line->id)->reservation_id)->toBe($reservation->id);
    }
});

it('splits value and party across the stays', function () {
    [$hotel, $type, $guest] = syncFixture();

    $reservation = syncBook($hotel, $type, $guest, attributes: ['reservation_value' => 100.01, 'adults' => 3, 'children' => 1]);

    [$first, $second] = $reservation->reservationRooms()->get()->map(fn ($line) => stayOf($line->id))->all();
    expect((float) $first->room_revenue)->toBe(50.01)
        ->and((float) $second->room_revenue)->toBe(50.0)
        ->and([$first->adults, $first->children])->toBe([3, 1])
        ->and([$second->adults, $second->children])->toBe([0, 0]);
});

it('adds a stay when a line is added, and cancels it when the line is cancelled', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest, units: 1);
    $kept = $reservation->reservationRooms()->sole();

    ReservationCreator::update($reservation, [], [['id' => $kept->id], ['room_type_id' => $type->id]]);
    expect($reservation->stays()->count())->toBe(2);

    $added = $reservation->reservationRooms()->where('id', '!=', $kept->id)->sole();
    ReservationCreator::update($reservation, [], [['id' => $kept->id]]);

    expect(stayOf($added->id)->status)->toBe(StayStatus::CANCELLED)
        ->and(stayOf($kept->id)->status)->toBe(StayStatus::EXPECTED);
});

it('cancels every stay with the reservation and brings them back with it', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest);

    ReservationCreator::update($reservation, ['status' => 'cancelled'], null);
    expect($reservation->stays()->pluck('status')->unique()->all())->toBe([StayStatus::CANCELLED]);

    ReservationCreator::update($reservation->fresh(), ['status' => 'confirmed'], null);
    expect($reservation->stays()->pluck('status')->unique()->all())->toBe([StayStatus::EXPECTED])
        ->and($reservation->stays()->count())->toBe(2);
});

it('moves the planned dates and the room of expected stays with the reservation', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest, units: 1);
    $line = $reservation->reservationRooms()->sole();
    $room = avRooms($hotel, $type, 1)[0];

    $newArrival = now()->addDays(20)->toDateString();
    $newDeparture = now()->addDays(22)->toDateString();
    ReservationCreator::update($reservation, ['arrival_date' => $newArrival, 'departure_date' => $newDeparture], [['id' => $line->id, 'room_id' => $room->id]]);

    $stay = stayOf($line->id);
    expect($stay->planned_arrival_date->toDateString())->toBe($newArrival)
        ->and($stay->planned_departure_date->toDateString())->toBe($newDeparture)
        ->and($stay->room_id)->toBe($room->id);
});

it('keeps an in-house stay\'s check-in time when the reservation changes', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest, units: 1);
    $line = $reservation->reservationRooms()->sole();
    $stay = stayOf($line->id);
    app(StayService::class)->checkIn($stay, now()->subHour());
    $reservation->update(['status' => 'checked_in']);
    $checkedInAt = $stay->fresh()->checked_in_at;

    $later = now()->addDays(15)->toDateString();
    ReservationCreator::update($reservation, ['departure_date' => $later], null);

    expect(stayOf($line->id)->checked_in_at->equalTo($checkedInAt))->toBeTrue()
        ->and(stayOf($line->id)->planned_departure_date->toDateString())->toBe($later);
});

it('creates nothing new when synced again', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest);

    app(StayService::class)->syncForReservation($reservation);
    app(StayService::class)->syncForReservation($reservation);

    expect(Stay::where('reservation_id', $reservation->id)->count())->toBe(2);
});

it('gives the reservation a primary stay that is never a cancelled one', function () {
    [$hotel, $type, $guest] = syncFixture();
    $reservation = syncBook($hotel, $type, $guest);
    [$first, $second] = $reservation->reservationRooms()->get()->all();

    ReservationCreator::update($reservation, [], [['id' => $second->id]]);

    expect($reservation->fresh()->stay->reservation_room_id)->toBe($second->id)
        ->and(Reservation::with('stay')->find($reservation->id)->stay->reservation_room_id)->toBe($second->id);
});
