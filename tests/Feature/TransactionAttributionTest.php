<?php

use App\Enums\StayStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\TransactionAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function attributionHotel(): Hotel
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Attribution Hotel',
        'slug' => 'attribution-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function stayInRoom(Hotel $hotel, string $roomNumber, ?string $checkedInAt, ?string $checkedOutAt): Stay
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => $roomNumber]);

    return Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'room_id' => $room->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-10',
        'checked_in_at' => $checkedInAt,
        'checked_out_at' => $checkedOutAt,
        'status' => $checkedOutAt ? StayStatus::DEPARTED : StayStatus::IN_HOUSE,
    ]);
}

it('never attributes a purchase to a guest who had not checked in yet', function () {
    $hotel = attributionHotel();
    stayInRoom($hotel, '304', '2026-09-05 15:00', null);

    $resolved = app(TransactionAttributionService::class)
        ->resolveStay('304', Carbon::parse('2026-09-03 20:00'), $hotel);

    expect($resolved)->toBeNull();
});

it('never attributes a purchase to a guest who had already checked out', function () {
    $hotel = attributionHotel();
    stayInRoom($hotel, '304', '2026-09-01 15:00', '2026-09-04 10:00');

    $resolved = app(TransactionAttributionService::class)
        ->resolveStay('304', Carbon::parse('2026-09-06 20:00'), $hotel);

    expect($resolved)->toBeNull();
});

it('attributes to the guest who was in the room at that moment', function () {
    $hotel = attributionHotel();
    $stay = stayInRoom($hotel, '304', '2026-09-01 15:00', '2026-09-08 10:00');

    $resolved = app(TransactionAttributionService::class)
        ->resolveStay('304', Carbon::parse('2026-09-04 20:00'), $hotel);

    expect($resolved?->id)->toBe($stay->id);
});

it('resolves the right stay when a room has hosted several guests in sequence', function () {
    $hotel = attributionHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '34304']);

    $guestA = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'a-'.uniqid(), 'channel' => 'booking_com']);
    $stayA = Stay::create([
        'hotel_id' => $hotel->id, 'guest_id' => $guestA->id, 'room_id' => $room->id,
        'planned_arrival_date' => '2026-09-01', 'planned_departure_date' => '2026-09-05',
        'checked_in_at' => '2026-09-01 14:00', 'checked_out_at' => '2026-09-05 10:00',
        'status' => StayStatus::DEPARTED,
    ]);

    $guestB = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'b-'.uniqid(), 'channel' => 'booking_com']);
    $stayB = Stay::create([
        'hotel_id' => $hotel->id, 'guest_id' => $guestB->id, 'room_id' => $room->id,
        'planned_arrival_date' => '2026-09-05', 'planned_departure_date' => '2026-09-09',
        'checked_in_at' => '2026-09-05 15:00', 'checked_out_at' => '2026-09-09 10:00',
        'status' => StayStatus::DEPARTED,
    ]);

    $resolved = app(TransactionAttributionService::class)
        ->resolveStay('34304', Carbon::parse('2026-09-07 19:30'), $hotel);

    expect($resolved?->id)->toBe($stayB->id)
        ->and($resolved?->id)->not->toBe($stayA->id);
});
