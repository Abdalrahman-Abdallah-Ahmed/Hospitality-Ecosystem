<?php

use App\Enums\StayStatus;
use App\Models\EventLog;
use App\Models\Reservation;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| A reservation with several rooms, checked in and out room by room or all at
| once (spec User Story 3).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function reservationCheckIn($test, Reservation $reservation, array $payload = [])
{
    return fdPost($test, $reservation->hotel->owner, "/api/reservation/{$reservation->id}/check-in", $payload);
}

function reservationCheckOut($test, Reservation $reservation, array $payload = [])
{
    return fdPost($test, $reservation->hotel->owner, "/api/reservation/{$reservation->id}/check-out", $payload);
}

it('gives a two-room reservation two stays', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();

    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);

    expect(fdStays($reservation))->toHaveCount(2);
});

it('checks every assigned room in with one action', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);

    reservationCheckIn($this, $reservation)
        ->assertOk()
        ->assertJsonPath('body.reservation.status', 'checked_in')
        ->assertJsonCount(2, 'body.stays');

    expect(collect(fdStays($reservation))->pluck('status')->unique()->all())->toBe([StayStatus::IN_HOUSE])
        ->and([$a->fresh()->status, $b->fresh()->status])->toBe(['occupied', 'occupied'])
        ->and(EventLog::where('event_type', 'stay.checked_in')->count())->toBe(2);
});

it('checks nothing in when one room cannot be, and lists it', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, null]);
    [, $unassigned] = fdStays($reservation);

    reservationCheckIn($this, $reservation)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$unassigned->id}.room" => 'Assign or name a room first.']);

    expect(collect(fdStays($reservation))->pluck('status')->unique()->all())->toBe([StayStatus::EXPECTED])
        ->and($a->fresh()->status)->toBe('available')
        ->and($reservation->fresh()->status->value)->toBe('confirmed');
});

it('names rooms for the unassigned lines in the same action', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, null]);
    [, $unassigned] = fdStays($reservation);

    reservationCheckIn($this, $reservation, ['rooms' => [['stay_id' => $unassigned->id, 'room_id' => $b->id]]])->assertOk();

    expect($unassigned->fresh()->room_id)->toBe($b->id)
        ->and($unassigned->fresh()->status)->toBe(StayStatus::IN_HOUSE);
});

it('checks in only the rooms still waiting, and marks the reservation checked in from the first room', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    [$first, $second] = fdStays($reservation);

    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-in")->assertOk();
    expect($reservation->fresh()->status->value)->toBe('checked_in')
        ->and($second->fresh()->status)->toBe(StayStatus::EXPECTED);

    $checkedInAt = $first->fresh()->checked_in_at;
    $this->travel(10)->minutes();
    reservationCheckIn($this, $reservation)->assertOk();

    expect($second->fresh()->status)->toBe(StayStatus::IN_HOUSE)
        ->and($first->fresh()->checked_in_at->equalTo($checkedInAt))->toBeTrue();
});

it('keeps the reservation checked in until its last room is out', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    [$first, $second] = fdStays($reservation);
    reservationCheckIn($this, $reservation)->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-out")->assertOk();
    expect($reservation->fresh()->status->value)->toBe('checked_in');

    fdPost($this, $hotel->owner, "/api/stays/{$second->id}/check-out")->assertOk();
    expect($reservation->fresh()->status->value)->toBe('checked_out');
});

it('checks out every room with one action: two departed stays, two dirty rooms, two cleaning tasks', function () {
    [$hotel, $type, [$a, $b]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, $b->id]);
    reservationCheckIn($this, $reservation)->assertOk();

    reservationCheckOut($this, $reservation)
        ->assertOk()
        ->assertJsonPath('body.reservation.status', 'checked_out')
        ->assertJsonCount(2, 'body.cleaning_tasks');

    expect(collect(fdStays($reservation))->pluck('status')->unique()->all())->toBe([StayStatus::DEPARTED])
        ->and([$a->fresh()->housekeeping_status->value, $b->fresh()->housekeeping_status->value])->toBe(['dirty', 'dirty'])
        ->and(Task::withoutGlobalScope('hotel')->pluck('room_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('refuses to check the last room out while another never arrived, until that room is cancelled', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id, null]);
    [$first, $waiting] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-in")->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-out")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stays.expected' => 'Cancel the rooms that did not arrive first: '.$reservation->reservation_id.' Deluxe (unassigned).']);
    reservationCheckOut($this, $reservation)->assertUnprocessable()->assertJsonValidationErrors(['stays.expected']);

    expect($first->fresh()->status)->toBe(StayStatus::IN_HOUSE);

    // Cancel the room that never came, then the last room can leave.
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['rooms' => [['id' => $first->reservation_room_id]]])
        ->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$first->id}/check-out")->assertOk();

    expect($reservation->fresh()->status->value)->toBe('checked_out')
        ->and($waiting->fresh()->status)->toBe(StayStatus::CANCELLED);
});

it('refuses a whole check-in of a reservation with no room waiting, and a whole check-out with nobody in', function () {
    [$hotel, $type, [$a]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$a->id], ['status' => 'cancelled']);

    reservationCheckIn($this, $reservation)->assertUnprocessable();
    reservationCheckOut($this, $reservation)->assertUnprocessable();
});
