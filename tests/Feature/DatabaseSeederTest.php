<?php

use App\Models\Reservation;
use App\Models\Room;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a fresh database, booking the demo guest into a real room', function () {
    $this->seed(DatabaseSeeder::class);

    $reservation = Reservation::withoutGlobalScope('hotel')->where('reservation_id', 'RES-1001')->sole();
    $line = $reservation->reservationRooms()->withoutGlobalScope('hotel')->sole();

    expect($line->room_id)->not->toBeNull()
        ->and(Room::withoutGlobalScope('hotel')->find($line->room_id)->status)->toBe('occupied');
});
