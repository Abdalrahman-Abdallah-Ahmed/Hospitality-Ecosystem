<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| `rooms:overlaps` (spec 020 FR-012). The constraint makes a clash impossible
| to write, so each test drops it first; the drop is rolled back with the
| RefreshDatabase transaction.
*/

beforeEach(function () {
    DB::statement('ALTER TABLE reservation_rooms DROP CONSTRAINT reservation_rooms_no_overlap');

    [$this->hotel, $this->type, [$this->room]] = fdHotel(1);
    [$other, $otherType] = fdHotel(0);
    $otherRoom = avRooms($other, $otherType, 1)[0];
    $otherRoom->update(['room_number' => $this->room->room_number]);

    // Same room number in another hotel, same nights: not a clash.
    createReservationWithRooms($other, [['room_type_id' => $otherType->id, 'room_id' => $otherRoom->id]], ['arrival_date' => '2026-11-02', 'departure_date' => '2026-11-05']);
    $this->first = createReservationWithRooms($this->hotel, [['room_type_id' => $this->type->id, 'room_id' => $this->room->id]], ['reservation_id' => 'RES-FIRST', 'arrival_date' => '2026-11-02', 'departure_date' => '2026-11-05']);
});

it('lists a room held twice on shared nights and fails', function () {
    createReservationWithRooms($this->hotel, [['room_type_id' => $this->type->id, 'room_id' => $this->room->id]], ['reservation_id' => 'RES-SECOND', 'arrival_date' => '2026-11-04', 'departure_date' => '2026-11-06']);

    $exit = Artisan::call('rooms:overlaps');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('RES-FIRST')
        ->and($output)->toContain('RES-SECOND')
        ->and($output)->toContain('1 overlapping room assignments')
        ->and(substr_count($output, '2026-11-0'))->toBe(2);
});

it('reports nothing when no room is held twice', function () {
    createReservationWithRooms($this->hotel, [['room_type_id' => $this->type->id, 'room_id' => $this->room->id]], ['arrival_date' => '2026-11-05', 'departure_date' => '2026-11-06']);

    $this->artisan('rooms:overlaps')
        ->expectsOutputToContain('No overlapping room assignments.')
        ->assertExitCode(0);
});
