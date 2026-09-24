<?php

use App\Enums\ActorKind;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\StayStatus;
use App\Models\EventLog;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Checking one room in (SPEC-024, spec User Story 1).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function checkInUri(Stay $stay): string
{
    return "/api/stays/{$stay->id}/check-in";
}

it('checks a guest into their assigned room', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertOk()
        ->assertJsonPath('body.reservation.status', 'checked_in')
        ->assertJsonPath('body.stays.0.status', 'in_house')
        ->assertJsonPath('body.warnings', []);

    expect($stay->fresh()->status)->toBe(StayStatus::IN_HOUSE)
        ->and($stay->fresh()->checked_in_at)->not->toBeNull()
        ->and($room->fresh()->status)->toBe('occupied');

    $audit = EventLog::where('event_type', 'stay.checked_in')->where('subject_id', $stay->id)->sole();
    expect($audit->actor_id)->toBe($hotel->owner->id)
        ->and($audit->actor_kind->value ?? $audit->actor_kind)->toBe(ActorKind::USER->value);
    expect(Transaction::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('refuses a line with no room when none is named, and changes nothing', function () {
    [$hotel, $type] = fdHotel();
    $reservation = fdBook($hotel, $type, [null]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room" => 'Assign or name a room first.']);

    expect($stay->fresh()->status)->toBe(StayStatus::EXPECTED)
        ->and($reservation->fresh()->status->value)->toBe('confirmed');
});

it('assigns a named room to an unassigned line and checks it in, in one action', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [null]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay), ['room_id' => $room->id])->assertOk();

    expect($reservation->reservationRooms()->sole()->room_id)->toBe($room->id)
        ->and($stay->fresh()->room_id)->toBe($room->id)
        ->and($stay->fresh()->status)->toBe(StayStatus::IN_HOUSE)
        ->and(EventLog::where('event_type', 'reservation_room.updated')->exists())->toBeTrue();
});

it('refuses a named room of another type, another hotel, or booked by someone else', function () {
    [$hotel, $type, [, $bookedRoom]] = fdHotel();
    $suite = avType($hotel, 'Suite');
    [$suiteRoom] = avRooms($hotel, $suite, 1);
    [$otherHotel, $otherType, [$foreignRoom]] = fdHotel();
    fdBook($hotel, $type, [$bookedRoom->id]);

    $reservation = fdBook($hotel, $type, [null]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay), ['room_id' => $suiteRoom->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room_id" => "Room {$suiteRoom->room_number} is not of the line's room type."]);

    fdPost($this, $hotel->owner, checkInUri($stay), ['room_id' => $foreignRoom->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room_id" => 'The selected room is not available.']);

    fdPost($this, $hotel->owner, checkInUri($stay), ['room_id' => $bookedRoom->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room_id" => 'overlapping nights']);

    expect($stay->fresh()->status)->toBe(StayStatus::EXPECTED)
        ->and($reservation->reservationRooms()->sole()->room_id)->toBeNull();
});

it('refuses to swap an assigned room at check-in', function () {
    [$hotel, $type, [$room, $other]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay), ['room_id' => $other->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room_id" => 'reassign it first']);
});

it('refuses a room that is out of order', function (array $roomState) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update($roomState);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room" => "Room {$room->room_number} is out of order."]);
})->with([
    'maintenance status' => [['status' => 'maintenance']],
    'blocked housekeeping' => [['housekeeping_status' => 'blocked']],
]);

it('refuses a room that another guest is in', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $first = fdBook($hotel, $type, [$room->id], ['departure_date' => now()->addDay()->toDateString()]);
    fdPost($this, $hotel->owner, checkInUri(fdStays($first)[0]))->assertOk();

    // A second booking that was put in the same room outside the rules (legacy data).
    $second = fdBook($hotel, $type, [null], ['arrival_date' => now()->toDateString()]);
    [$stay] = fdStays($second);
    $second->reservationRooms()->sole()->update(['room_id' => $room->id]);
    $stay->update(['room_id' => $room->id]);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.room" => "Room {$room->room_number} is occupied."]);
});

it('refuses a reservation that is not confirmed, and a cancelled line', function (string $status) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id], ['status' => $status]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))->assertUnprocessable();

    expect($stay->fresh()->status)->not->toBe(StayStatus::IN_HOUSE);
})->with(['pending', 'cancelled']);

it('refuses a guest whose arrival is tomorrow', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now()->addDay()->toDateString(),
        'departure_date' => now()->addDays(3)->toDateString(),
    ]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.dates" => 'Arrival is '.now()->addDay()->toDateString().'.']);
});

it('accepts a late arrival before the departure date, and refuses one on or after it', function () {
    [$hotel, $type, [$late, $past]] = fdHotel();
    $lateBooking = fdBook($hotel, $type, [$late->id], [
        'arrival_date' => now()->subDay()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
    ]);
    $pastBooking = fdBook($hotel, $type, [$past->id], [
        'arrival_date' => now()->subDays(2)->toDateString(),
        'departure_date' => now()->toDateString(),
    ]);

    fdPost($this, $hotel->owner, checkInUri(fdStays($lateBooking)[0]))->assertOk();
    fdPost($this, $hotel->owner, checkInUri(fdStays($pastBooking)[0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stays.'.fdStays($pastBooking)[0]->id.'.dates' => 'The departure date has passed; correct the reservation dates first.']);
});

it('does nothing the second time and keeps the first check-in time', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))->assertOk();
    $checkedInAt = $stay->fresh()->checked_in_at;
    $this->travel(5)->minutes();

    fdPost($this, $hotel->owner, checkInUri($stay))->assertOk()->assertJsonPath('body.stays.0.status', 'in_house');

    expect($stay->fresh()->checked_in_at->equalTo($checkedInAt))->toBeTrue()
        ->and(EventLog::where('event_type', 'stay.checked_in')->where('subject_id', $stay->id)->count())->toBe(1);
});

it('checks in a room that is not clean, with a warning', function () {
    [$hotel, $type, [$room]] = fdHotel();
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['housekeeping_status' => HousekeepingStatusesEnum::DIRTY->value]);
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkInUri($stay))
        ->assertOk()
        ->assertJsonPath('body.warnings.0.stay_id', $stay->id)
        ->assertJsonPath('body.warnings.0.message', "Room {$room->room_number} is dirty.");
});

it('records an earlier actual time from today, with the entry time on the same audit row', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $this->travelTo(now()->startOfDay()->addHours(15));
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $earlier = now()->startOfDay()->addHours(13)->toIso8601String();

    fdPost($this, $hotel->owner, checkInUri($stay), ['checked_in_at' => $earlier])->assertOk();

    expect($stay->fresh()->checked_in_at->toIso8601String())->toBe($earlier);

    $audit = EventLog::where('event_type', 'stay.checked_in')->where('subject_id', $stay->id)->sole();
    expect($audit->changes)->toHaveKey('entered_at')
        ->and($audit->changes['checked_in_at']['to'])->toContain(now()->toDateString())->toContain('13:00');
});

it('refuses an actual time from yesterday or the future', function (string $when) {
    [$hotel, $type, [$room]] = fdHotel();
    $this->travelTo(now()->startOfDay()->addHours(12));
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $time = $when === 'yesterday' ? now()->subDay()->toIso8601String() : now()->addHour()->toIso8601String();

    fdPost($this, $hotel->owner, checkInUri($stay), ['checked_in_at' => $time])
        ->assertUnprocessable()
        ->assertJsonPath('errors.checked_in_at.0', 'The time must be today (hotel time) and not in the future.');

    expect($stay->fresh()->status)->toBe(StayStatus::EXPECTED);
})->with(['yesterday', 'future']);
