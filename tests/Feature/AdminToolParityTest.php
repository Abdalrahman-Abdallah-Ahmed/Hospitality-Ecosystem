<?php

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\TaskCategory;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-055 FR-010, SC-005: the AI and the staff screens share one code path.
 * Each case runs the same request through the API in one hotel and through
 * the Admin AI tool in an identical second hotel, then compares what was
 * saved, or the refusal's words.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

function aatApi($test, string $method, string $uri, array $payload, array $seed)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($seed['admin'], 'sanctum')->json($method, $uri, $payload);
}

it('registers a new guest the same way, and returns the existing one for a known phone', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');
    $phone = '+20 100 555 1234';

    aatApi($this, 'POST', '/api/guest', ['hotel_id' => $api['hotel']->id, 'first_name' => 'Nour', 'phone_number' => $phone, 'nationality' => 'EG'], $api)->assertCreated();
    aatCall(aatTool($ai['admin'], 'CreateGuestTool'), ['first_name' => 'Nour', 'phone_number' => $phone, 'nationality' => 'EG']);

    $shape = fn (array $seed) => Guest::withoutGlobalScope('hotel')->where('hotel_id', $seed['hotel']->id)->where('first_name', 'Nour')
        ->get(['first_name', 'phone_number', 'nationality'])->toArray();

    expect($shape($ai))->toBe($shape($api));

    // Again, the same phone: neither path creates a second guest.
    aatApi($this, 'POST', '/api/guest', ['hotel_id' => $api['hotel']->id, 'first_name' => 'Nour 2', 'phone_number' => $phone], $api)->assertCreated();
    aatCall(aatTool($ai['admin'], 'CreateGuestTool'), ['first_name' => 'Nour 2', 'phone_number' => $phone]);

    $count = fn (array $seed) => Guest::withoutGlobalScope('hotel')->where('hotel_id', $seed['hotel']->id)->where('phone_number', $phone)->count();

    expect($count($ai))->toBe($count($api))->toBe(1);
});

it('changes a reservation\'s dates and party the same way', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');
    $departure = now()->addDays(3)->toDateString();

    aatApi($this, 'PUT', "/api/reservation/{$api['reservation']->id}", ['departure_date' => $departure, 'adults' => 2], $api)->assertOk();
    aatCall(aatTool($ai['admin'], 'UpdateReservationTool'), ['code' => $ai['code'], 'departure_date' => $departure, 'adults' => 2]);

    $shape = fn (Reservation $r) => $r->fresh()->only(['arrival_date', 'departure_date', 'adults', 'children', 'status']);

    expect($shape($ai['reservation']))->toEqual($shape($api['reservation']));
});

it('refuses an oversold room type with the same words', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');

    $message = aatApi($this, 'PUT', "/api/reservation/{$api['reservation']->id}", [
        'rooms' => [['id' => $api['reservation']->reservationRooms()->first()->id], ['room_type_id' => $api['type']->id, 'quantity' => 4]],
    ], $api)->assertStatus(422)->json('message');

    $result = aatCall(aatTool($ai['admin'], 'UpdateReservationTool'), ['code' => $ai['code'], 'rooms' => [['room_type' => 'Deluxe', 'quantity' => 5]]]);

    expect($result)->toContain(rtrim(explode(' (and', $message)[0], '.'));
});

it('cancels a reservation the same way', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');

    aatApi($this, 'PUT', "/api/reservation/{$api['reservation']->id}", ['status' => 'cancelled'], $api)->assertOk();
    aatCall(aatTool($ai['admin'], 'CancelReservationTool'), ['code' => $ai['code']]);

    $shape = fn (Reservation $r) => [$r->fresh()->status->value, $r->reservationRooms()->pluck('status')->map->value->all()];

    expect($shape($ai['reservation']))->toBe($shape($api['reservation']));
});

it('refuses a room of the wrong type with the same words', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');
    $apiSuite = aatRoom($api['hotel'], avType($api['hotel'], 'Suite'), '501');
    aatRoom($ai['hotel'], avType($ai['hotel'], 'Suite'), '501');

    $message = aatApi($this, 'PUT', "/api/reservation/{$api['reservation']->id}", [
        'rooms' => [['id' => $api['reservation']->reservationRooms()->first()->id, 'room_id' => $apiSuite->id]],
    ], $api)->assertStatus(422)->json('message');

    $result = aatCall(aatTool($ai['admin'], 'AssignRoomsTool'), ['code' => $ai['code'], 'assignments' => [['room_number' => '501']]]);

    expect($message)->toContain("Room 501 is not of the line's room type")
        ->and($result)->toContain("Room 501 is not of the line's room type");
});

it('refuses a task category that is not the team\'s with the same words', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');

    $setup = function (array $seed) {
        $housekeeping = Team::create(['hotel_id' => $seed['hotel']->id, 'name' => 'Room Crew']);
        $engineering = Team::create(['hotel_id' => $seed['hotel']->id, 'name' => 'Fix Crew']);
        $plumbing = TaskCategory::create(['hotel_id' => $seed['hotel']->id, 'team_id' => $engineering->id, 'name' => 'Plumbing']);

        return [$housekeeping, $plumbing];
    };

    [$apiTeam, $apiCategory] = $setup($api);
    [$aiTeam, $aiCategory] = $setup($ai);

    $message = aatApi($this, 'POST', '/api/task', ['hotel_id' => $api['hotel']->id, 'title' => 'Tap', 'assigned_to_team_id' => $apiTeam->id, 'task_category_id' => $apiCategory->id], $api)
        ->assertStatus(403)->json('message');
    $result = aatCall(aatTool($ai['admin'], 'CreateTaskTool'), ['title' => 'Tap', 'assigned_to_team_id' => $aiTeam->id, 'task_category_id' => $aiCategory->id]);

    expect($result)->toContain($message);
});

it('sets a room\'s housekeeping status the same way', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');

    aatApi($this, 'PUT', "/api/room/{$api['rooms']['101']->id}/housekeeping-status", ['housekeeping_status' => 'dirty', 'reason' => 'Spill'], $api)->assertOk();
    aatCall(aatTool($ai['admin'], 'SetHousekeepingStatusTool'), ['room_number' => '101', 'housekeeping_status' => 'dirty', 'reason' => 'Spill']);

    $shape = fn (array $seed) => $seed['rooms']['101']->fresh()->only(['status', 'housekeeping_status']);

    expect($shape($ai))->toEqual($shape($api));
});

it('refuses an activity booking past capacity with the same words', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');
    $date = now()->addDays(4)->toDateString();

    foreach ([$api, $ai] as $seed) {
        $seed['activity']->update(['daily_capacity' => 1, 'name' => 'Cruise']);
    }

    $message = aatApi($this, 'POST', '/api/booking', [
        'guest_id' => $api['guest']->id, 'activity_id' => $api['activity']->id, 'scheduled_date' => $date, 'pax' => 2, 'charge_model' => 'pay_on_site',
    ], $api)->assertStatus(422)->json('message');

    $result = aatCall(aatTool($ai['admin'], 'CreateActivityBookingTool'), [
        'guest' => $ai['guest']->id, 'activity' => 'Cruise', 'scheduled_for' => $date, 'pax' => 2,
    ]);

    expect($result)->toContain(rtrim(explode(' (and', $message)[0], '.'))
        ->and(Booking::withoutGlobalScope('hotel')->where('hotel_id', $ai['hotel']->id)->count())->toBe(1);
});

it('cancels a booking the same way', function () {
    $api = aatSeed('Api');
    $ai = aatSeed('Ai');

    aatApi($this, 'POST', "/api/booking/{$api['booking']->id}/status", ['status' => 'cancelled', 'reason' => 'Weather'], $api)->assertOk();
    aatCall(aatTool($ai['admin'], 'UpdateBookingStatusTool'), ['booking' => $ai['booking']->reference, 'status' => 'cancelled', 'reason' => 'Weather']);

    $shape = fn (array $seed) => $seed['booking']->fresh()->only(['status', 'cancellation_reason']);

    expect($shape($ai))->toEqual($shape($api));
});
