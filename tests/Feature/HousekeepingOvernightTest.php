<?php

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\StayStatus;
use App\Jobs\MakeRoomDirtyOvernightJob;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function housekeepingHotel(string $name = 'Housekeeping Hotel'): Hotel
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function housekeepingRoom(Hotel $hotel, string $number, array $overrides = []): Room
{
    return Room::create(array_merge([
        'hotel_id' => $hotel->id,
        'room_number' => $number,
        'room_type' => 'double',
        'floor' => '1',
    ], $overrides));
}

function housekeepingStay(Hotel $hotel, Room $room, StayStatus $status): Stay
{
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);

    return Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'room_id' => $room->id,
        'planned_arrival_date' => now()->subDay()->toDateString(),
        'planned_departure_date' => now()->addDay()->toDateString(),
        'status' => $status,
    ]);
}

it('marks a room with an in-house stay dirty', function () {
    $hotel = housekeepingHotel();
    $room = housekeepingRoom($hotel, '101');
    housekeepingStay($hotel, $room, StayStatus::IN_HOUSE);

    (new MakeRoomDirtyOvernightJob)->handle();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('leaves a room with no stay clean', function () {
    $hotel = housekeepingHotel();
    $room = housekeepingRoom($hotel, '102');

    (new MakeRoomDirtyOvernightJob)->handle();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('ignores stays that are not in house', function () {
    $hotel = housekeepingHotel();

    $expected = housekeepingRoom($hotel, '201');
    housekeepingStay($hotel, $expected, StayStatus::EXPECTED);

    $departed = housekeepingRoom($hotel, '202');
    housekeepingStay($hotel, $departed, StayStatus::DEPARTED);

    (new MakeRoomDirtyOvernightJob)->handle();

    expect($expected->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and($departed->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('does not downgrade a blocked room to dirty', function () {
    $hotel = housekeepingHotel();
    $room = housekeepingRoom($hotel, '301', ['housekeeping_status' => 'blocked']);
    housekeepingStay($hotel, $room, StayStatus::IN_HOUSE);

    (new MakeRoomDirtyOvernightJob)->handle();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::BLOCKED);
});

it('leaves an already dirty room dirty when run twice', function () {
    $hotel = housekeepingHotel();
    $room = housekeepingRoom($hotel, '401');
    housekeepingStay($hotel, $room, StayStatus::IN_HOUSE);

    (new MakeRoomDirtyOvernightJob)->handle();
    (new MakeRoomDirtyOvernightJob)->handle();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('sweeps every hotel, not just one', function () {
    $first = housekeepingHotel('First Hotel');
    $second = housekeepingHotel('Second Hotel');

    $firstRoom = housekeepingRoom($first, '101');
    housekeepingStay($first, $firstRoom, StayStatus::IN_HOUSE);

    $secondRoom = housekeepingRoom($second, '101');
    housekeepingStay($second, $secondRoom, StayStatus::IN_HOUSE);

    (new MakeRoomDirtyOvernightJob)->handle();

    expect($firstRoom->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
        ->and($secondRoom->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});
