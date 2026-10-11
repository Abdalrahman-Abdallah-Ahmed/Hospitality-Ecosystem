<?php

use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * SPEC-083 FR-034: every record type an AI write can change has a readable
 * history, so any entry in the AI activity list can be followed to it.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('reads the history of bookings, rooms and room types', function () {
    $s = aatSeed();
    $room = Room::withoutGlobalScope('hotel')->where('hotel_id', $s['hotel']->id)->firstOrFail();
    $room->update(['floor' => '7']);

    foreach ([
        "booking/{$s['booking']->id}" => 'booking.created',
        "room/{$room->id}" => 'room.updated',
        "room-type/{$s['type']->id}" => 'room_type.created',
    ] as $path => $eventType) {
        $events = cmaRequest($this, $s['admin'], 'GET', "/api/history/{$path}")->assertOk()->json('body.data');

        expect(collect($events)->pluck('event_type'))->toContain($eventType);
    }
});

it('keeps a deleted guest memory\'s history readable, without its content', function () {
    $hotel = cmaHotel();
    $memory = cmaMemory(gsGuest($hotel), ['content' => 'Allergic to nuts, keep secret']);
    $id = $memory->id;
    $memory->delete();

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/history/guest-memory/{$id}")->assertOk();

    expect(collect($response->json('body.data'))->pluck('event_type')->all())->toBe(['guest_memory.deleted', 'guest_memory.created'])
        ->and($response->getContent())->not->toContain('Allergic');
});

it('hides another hotel\'s guest memory history, and unknown ids', function () {
    $hotelA = cmaHotel();
    $hotelB = cmaHotel();
    $memoryB = cmaMemory(gsGuest($hotelB));

    cmaRequest($this, cmaAdmin($hotelA), 'GET', "/api/history/guest-memory/{$memoryB->id}")->assertNotFound();
    cmaRequest($this, cmaAdmin($hotelA), 'GET', '/api/history/guest-memory/'.Str::uuid())->assertNotFound();
});
