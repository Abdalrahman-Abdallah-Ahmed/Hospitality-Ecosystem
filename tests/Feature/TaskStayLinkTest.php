<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;

/*
| Tasks linked to the stay they belong to (spec User Story 6, FR-018,
| FR-019).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function taskRequest($test, $user, string $method, string $uri, array $payload = [])
{
    if ($method === 'POST') {
        $payload = ['hotel_id' => $user->hotel_id, ...$payload];
    }

    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->json($method, $uri, $payload);
}

/**
 * @return array{0: Hotel, 1: Reservation, 2: list<Stay>}
 */
function inHouseReservation($test, int $rooms = 1): array
{
    [$hotel, $type, $roomList] = fdHotel($rooms + 1);
    $reservation = fdBook($hotel, $type, array_map(fn ($room) => $room->id, array_slice($roomList, 0, $rooms)));
    fdPost($test, $hotel->owner, "/api/reservation/{$reservation->id}/check-in")->assertOk();

    return [$hotel, $reservation, fdStays($reservation)];
}

it('links the check-out cleaning task to the departed stay', function () {
    [$hotel, , [$stay]] = inHouseReservation($this);

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();

    expect(Task::withoutGlobalScope('hotel')->sole()->stay_id)->toBe($stay->id);
});

it('lets staff link a task to a stay, filling in its room, reservation and guest', function () {
    [$hotel, $reservation, [$stay]] = inHouseReservation($this);

    $response = taskRequest($this, $hotel->owner, 'POST', '/api/task', ['title' => 'Extra pillows', 'stay_id' => $stay->id])
        ->assertCreated()
        ->assertJsonPath('body.stay_id', $stay->id);

    $task = Task::find($response->json('body.id'));
    expect([$task->room_id, $task->reservation_id, $task->guest_id])->toBe([$stay->room_id, $reservation->id, $reservation->guest_id]);
});

it('rejects another hotel\'s stay, and a room or reservation that does not match the stay', function () {
    [$hotel, , [$stay]] = inHouseReservation($this, rooms: 2);
    [$otherHotel, , [$foreignStay]] = inHouseReservation($this);
    $otherRoom = fdStays(Reservation::find($stay->reservation_id))[1]->room_id;

    taskRequest($this, $hotel->owner, 'POST', '/api/task', ['title' => 'X', 'stay_id' => $foreignStay->id])
        ->assertForbidden();
    taskRequest($this, $hotel->owner, 'POST', '/api/task', ['title' => 'X', 'stay_id' => $stay->id, 'room_id' => $otherRoom])
        ->assertUnprocessable();

    $task = Task::create(['hotel_id' => $hotel->id, 'title' => 'Existing', 'room_id' => $otherRoom]);
    taskRequest($this, $hotel->owner, 'PUT', "/api/task/{$task->id}", ['stay_id' => $stay->id])
        ->assertUnprocessable();
});

it('filters tasks by stay', function () {
    [$hotel, , [$first, $second]] = inHouseReservation($this, rooms: 2);
    $mine = taskRequest($this, $hotel->owner, 'POST', '/api/task', ['title' => 'Mine', 'stay_id' => $first->id])->json('body.id');
    taskRequest($this, $hotel->owner, 'POST', '/api/task', ['title' => 'Theirs', 'stay_id' => $second->id]);

    $ids = collect(taskRequest($this, $hotel->owner, 'GET', "/api/task?filter[stay_id]={$first->id}")->assertOk()->json('body.data'))->pluck('id')->all();

    expect($ids)->toBe([$mine]);
});

it('links a Concierge request to the guest\'s only room in the house', function () {
    [$hotel, $reservation, [$stay]] = inHouseReservation($this);
    $guest = Guest::find($reservation->guest_id);

    (new CreateGuestServiceRequestTool($guest, $hotel, $reservation))->handle(new ToolRequest(['title' => 'Towels', 'description' => 'Two more towels']));

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->stay_id)->toBe($stay->id)->and($task->room_id)->toBe($stay->room_id);
});

it('links a Concierge request to the room the guest names when they are in several, and to none when they do not say', function () {
    [$hotel, $reservation, [$first, $second]] = inHouseReservation($this, rooms: 2);
    $guest = Guest::find($reservation->guest_id);
    $tool = new CreateGuestServiceRequestTool($guest, $hotel, $reservation);

    $tool->handle(new ToolRequest(['title' => 'Towels', 'description' => 'For the kids', 'room_number' => $second->room->room_number]));
    $tool->handle(new ToolRequest(['title' => 'Water', 'description' => 'Somewhere']));

    $named = Task::withoutGlobalScope('hotel')->where('title', 'Towels')->sole();
    $unnamed = Task::withoutGlobalScope('hotel')->where('title', 'Water')->sole();

    expect([$named->stay_id, $named->room_id])->toBe([$second->id, $second->room_id])
        ->and([$unnamed->stay_id, $unnamed->room_id])->toBe([null, null])
        ->and([$unnamed->reservation_id, $unnamed->guest_id])->toBe([$reservation->id, $guest->id]);
});
