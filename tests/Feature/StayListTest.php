<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The front desk's lists: arrivals, departures, in-house, and the stays
| index (spec User Story 4, FR-016, FR-017).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @return list<string> the stay ids in a list response, in order
 */
function listedIds($response): array
{
    return collect($response->json('body.stays'))->pluck('id')->all();
}

it('lists today\'s and late arrivals, flagging the late one, and leaves cancelled rooms out', function () {
    [$hotel, $type, [$a, $b, $c]] = fdHotel();
    [$today] = fdStays(fdBook($hotel, $type, [$a->id]));
    [$late] = fdStays(fdBook($hotel, $type, [$b->id], ['arrival_date' => now()->subDay()->toDateString()]));
    [$stale] = fdStays(fdBook($hotel, $type, [null], ['arrival_date' => now()->subDays(3)->toDateString(), 'departure_date' => now()->subDay()->toDateString()]));
    fdBook($hotel, $type, [$c->id], ['status' => 'cancelled']);
    fdBook($hotel, $type, [null], ['arrival_date' => now()->addDay()->toDateString(), 'departure_date' => now()->addDays(3)->toDateString()]);

    $response = fdGet($this, $hotel->owner, '/api/stays/arrivals')->assertOk()
        ->assertJsonPath('body.date', now()->toDateString());

    expect(listedIds($response))->toEqualCanonicalizing([$today->id, $late->id, $stale->id]);

    $byId = collect($response->json('body.stays'))->keyBy('id');
    expect($byId[$late->id]['is_late'])->toBeTrue()
        ->and($byId[$today->id]['is_late'])->toBeFalse()
        ->and($byId[$stale->id]['is_past_departure'])->toBeTrue()
        ->and($byId[$stale->id]['room'])->toBeNull()
        ->and($byId[$today->id]['room']['room_number'])->toBe($a->room_number)
        ->and($byId[$today->id]['room_type']['name'])->toBe('Deluxe');
});

it('lists departures due today and overdue ones, and everyone in the house', function () {
    [$hotel, $type, [$a, $b, $c]] = fdHotel();
    $this->travelTo(now()->subDays(2));
    [$dueToday] = fdStays(fdBook($hotel, $type, [$a->id], ['departure_date' => now()->addDays(2)->toDateString()]));
    [$overdue] = fdStays(fdBook($hotel, $type, [$b->id], ['departure_date' => now()->addDay()->toDateString()]));
    [$staying] = fdStays(fdBook($hotel, $type, [$c->id], ['departure_date' => now()->addDays(5)->toDateString()]));

    foreach ([$dueToday, $overdue, $staying] as $stay) {
        fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    }
    $this->travelBack();

    $departures = fdGet($this, $hotel->owner, '/api/stays/departures')->assertOk();
    expect(listedIds($departures))->toEqualCanonicalizing([$dueToday->id, $overdue->id]);
    expect(collect($departures->json('body.stays'))->keyBy('id')[$overdue->id]['is_overdue'])->toBeTrue();

    expect(listedIds(fdGet($this, $hotel->owner, '/api/stays/in-house')->assertOk()))
        ->toEqualCanonicalizing([$dueToday->id, $overdue->id, $staying->id]);
});

it('lists another day when asked, and rejects a malformed date', function () {
    [$hotel, $type, [$a]] = fdHotel();
    [$tomorrow] = fdStays(fdBook($hotel, $type, [$a->id], ['arrival_date' => now()->addDay()->toDateString(), 'departure_date' => now()->addDays(3)->toDateString()]));

    expect(listedIds(fdGet($this, $hotel->owner, '/api/stays/arrivals')))->toBe([]);
    expect(listedIds(fdGet($this, $hotel->owner, '/api/stays/arrivals?date='.now()->addDay()->toDateString())))->toBe([$tomorrow->id]);

    fdGet($this, $hotel->owner, '/api/stays/arrivals?date=tomorrow')->assertUnprocessable();
});

it('makes a super admin name the hotel', function () {
    [$hotel, $type, [$a]] = fdHotel();
    [$stay] = fdStays(fdBook($hotel, $type, [$a->id]));
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    fdGet($this, $superAdmin, '/api/stays/arrivals')->assertForbidden();
    expect(listedIds(fdGet($this, $superAdmin, "/api/stays/arrivals?hotel_id={$hotel->id}")->assertOk()))->toBe([$stay->id]);
});

it('filters and searches the stays index, and shows one stay', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $first = fdBook($hotel, $type, [$a->id], ['reservation_id' => 'RES-FINDME']);
    fdBook($hotel, $type, [$b->id]);
    [$stay] = fdStays($first);

    $index = fn (string $query) => collect(fdGet($this, $hotel->owner, "/api/stays?{$query}")->assertOk()->json('body.data'))->pluck('id')->all();

    expect($index('filter[reservation_id]='.$first->id))->toBe([$stay->id])
        ->and($index('filter[room_id]='.$a->id))->toBe([$stay->id])
        ->and($index('search=FINDME'))->toBe([$stay->id])
        ->and($index('filter[status]=expected'))->toHaveCount(2);

    fdGet($this, $hotel->owner, "/api/stays/{$stay->id}")->assertOk()
        ->assertJsonPath('body.id', $stay->id)
        ->assertJsonPath('body.reservation_room_id', $stay->reservation_room_id);
});

it('shows the arrivals of a 500-room hotel in under 2 seconds with a fixed number of queries', function () {
    [$hotel, $type] = fdHotel(rooms: 0);
    $now = now();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(6), 'channel' => 'booking_com']);
    $rooms = $reservations = $lines = $stays = [];

    for ($i = 1; $i <= 500; $i++) {
        $roomId = (string) Str::uuid();
        $reservationId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $rooms[] = ['id' => $roomId, 'hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_number' => (string) (1000 + $i), 'status' => 'available', 'housekeeping_status' => 'clean', 'created_at' => $now, 'updated_at' => $now];
        $reservations[] = ['id' => $reservationId, 'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => "RES-{$i}", 'arrival_date' => $now->toDateString(), 'departure_date' => $now->copy()->addDays(2)->toDateString(), 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now];
        $lines[] = ['id' => $lineId, 'hotel_id' => $hotel->id, 'reservation_id' => $reservationId, 'room_type_id' => $type->id, 'room_id' => $roomId, 'status' => 'reserved', 'created_at' => $now, 'updated_at' => $now];
        $stays[] = ['id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => $reservationId, 'reservation_room_id' => $lineId, 'room_id' => $roomId, 'planned_arrival_date' => $now->toDateString(), 'planned_departure_date' => $now->copy()->addDays(2)->toDateString(), 'status' => 'expected', 'currency' => 'USD', 'created_at' => $now, 'updated_at' => $now];
    }

    foreach ([Room::class => $rooms, Reservation::class => $reservations, ReservationRoom::class => $lines, Stay::class => $stays] as $model => $rows) {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table((new $model)->getTable())->insert($chunk);
        }
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = microtime(true);

    $response = fdGet($this, $hotel->owner, '/api/stays/arrivals')->assertOk();

    $elapsed = microtime(true) - $started;
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'stays') || str_contains($query['query'], 'guests') || str_contains($query['query'], 'reservation'))->count();
    DB::disableQueryLog();

    expect($response->json('body.stays'))->toHaveCount(500)
        ->and($elapsed)->toBeLessThan(2.0)
        ->and($queries)->toBeLessThanOrEqual(8);
});
