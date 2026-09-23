<?php

use App\Ai\Tools\GetOwnReservationTool;
use App\Ai\Tools\GetReservationsTool;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function aiToolsHotel(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create(['owner_id' => $owner->id, 'name' => 'Tools Hotel', 'slug' => 'tools-'.$owner->id, 'currency' => 'USD']);
}

it('shows a guest only their own reservation rooms and a per-type summary', function () {
    $hotel = aiToolsHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $mine = createReservationWithRooms($hotel, [['room_id' => $room->id], [], ['status' => 'cancelled']]);
    createReservationWithRooms($hotel, [['room_type_id' => roomTypeIdFor($hotel)]]);

    $data = json_decode((string) (new GetOwnReservationTool($mine))->handle(new Request([])), true);

    expect($data['id'])->toBe($mine->id)
        ->and($data['rooms'])->toHaveCount(3)
        ->and(collect($data['rooms'])->pluck('room_number')->filter()->values()->all())->toBe(['101'])
        ->and($data['room_summary'])->toBe(['2 × Standard']);
});

it('lists today\'s arrivals with their rooms, for this hotel only', function () {
    $hotel = aiToolsHotel();
    $otherHotel = aiToolsHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $ours = createReservationWithRooms($hotel, [['room_id' => $room->id], []], ['arrival_date' => now()->toDateString()]);
    createReservationWithRooms($otherHotel, [[]], ['arrival_date' => now()->toDateString()]);

    $data = json_decode((string) (new GetReservationsTool($hotel))->handle(new Request([])), true);

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($ours->id)
        ->and($data[0]['room_summary'])->toBe(['2 × Standard'])
        ->and(collect($data[0]['rooms'])->pluck('room_number')->all())->toBe(['101', null]);
});
