<?php

use App\Enums\Permission;
use App\Models\EventLog;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
| PUT /api/reservation/{reservation}/rooms (spec 020 US1, US2): assigning,
| changing and removing the physical rooms of a reservation's lines.
*/

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    [$this->hotel, $this->type, $this->rooms] = fdHotel(4);
    $this->admin = $this->hotel->owner;
});

function raPut($test, $user, Reservation $reservation, array $assignments, array $extra = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}/rooms", ['assignments' => $assignments, ...$extra]);
}

/**
 * @return list<ReservationRoom>
 */
function raLines(Reservation $reservation): array
{
    return $reservation->reservationRooms()->orderBy('created_at')->orderBy('id')->get()->all();
}

function raAt(int $fromToday, int $nights): array
{
    return ['arrival_date' => now()->addDays($fromToday)->toDateString(), 'departure_date' => now()->addDays($fromToday + $nights)->toDateString()];
}

it('assigns rooms to two lines in one call, auditing each', function () {
    $reservation = fdBook($this->hotel, $this->type, [null, null], raAt(2, 3));
    [$first, $second] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [
        ['line_id' => $first->id, 'room_id' => $this->rooms[0]->id],
        ['line_id' => $second->id, 'room_id' => $this->rooms[1]->id],
    ])->assertOk()->assertJsonPath('body.warnings', []);

    expect($first->fresh()->room_id)->toBe($this->rooms[0]->id)
        ->and($second->fresh()->room_id)->toBe($this->rooms[1]->id)
        ->and(EventLog::where('event_type', 'reservation_room.updated')->whereIn('subject_id', [$first->id, $second->id])->where('actor_id', $this->admin->id)->count())->toBe(2);
});

it('refuses a room another reservation holds on an overlapping night, naming it', function () {
    $other = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], [...raAt(2, 3), 'reservation_id' => 'RES-HOLDER']);
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(4, 2));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => "Room {$this->rooms[0]->room_number} is booked for RES-HOLDER on overlapping nights."]);

    expect($line->fresh()->room_id)->toBeNull()
        ->and($other->reservationRooms()->sole()->room_id)->toBe($this->rooms[0]->id);
});

it('accepts back-to-back bookings of one room', function () {
    fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(2, 3));
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(5, 2));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])->assertOk();
});

it('changes nothing when one assignment in the call breaks a rule', function () {
    fdBook($this->hotel, $this->type, [$this->rooms[1]->id], [...raAt(2, 3), 'reservation_id' => 'RES-HOLDER']);
    $reservation = fdBook($this->hotel, $this->type, [null, null], raAt(2, 3));
    [$first, $second] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [
        ['line_id' => $first->id, 'room_id' => $this->rooms[0]->id],
        ['line_id' => $second->id, 'room_id' => $this->rooms[1]->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['assignments.1.room_id']);

    expect($first->fresh()->room_id)->toBeNull();
});

it('refuses a room of another hotel without naming it', function () {
    [$other, $otherType, [$foreign]] = fdHotel(1);
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $foreign->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => 'The selected room is not available.']);
});

it('refuses a line that is not on the reservation', function () {
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    [$elsewhere] = raLines(fdBook($this->hotel, $this->type, [null], raAt(2, 3)));

    raPut($this, $this->admin, $reservation, [['line_id' => $elsewhere->id, 'room_id' => $this->rooms[0]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.line_id' => 'This room line is not on the reservation.']);
});

it('refuses room changes on a reservation that holds no rooms', function (string $status) {
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    $reservation->forceFill(['status' => $status])->saveQuietly();
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => "Room changes are closed for this reservation ({$status})."]);
})->with(['cancelled', 'checked_out', 'no_show']);

it('refuses a cancelled line', function () {
    $reservation = fdBook($this->hotel, $this->type, [null, null], raAt(2, 3));
    [$line] = raLines($reservation);
    $line->update(['status' => 'cancelled']);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => 'This room line is cancelled.']);
});

it('changes nothing, and audits nothing, when the assignment is already in place', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(2, 3));
    [$line] = raLines($reservation);
    $before = EventLog::count();

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])->assertOk();

    expect(EventLog::count())->toBe($before);
});

it('assigns an out-of-order room with a warning', function () {
    $this->rooms[0]->update(['status' => 'out_of_order', 'out_of_order_reason' => 'Leak']);
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]])
        ->assertOk()
        ->assertJsonPath('body.warnings', ["Room {$this->rooms[0]->room_number} is out of order; return it to service before check-in."]);
});

it('answers 404 for another hotel\'s reservation', function () {
    [$other, $otherType] = fdHotel(1);
    $reservation = fdBook($other, $otherType, [null], raAt(2, 3));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => null]])->assertNotFound();
});

it('leaves a line without a room when its room is deleted, and lists it as unassigned', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(1, 2));
    [$line] = raLines($reservation);

    $this->rooms[0]->delete();

    expect($line->fresh()->room_id)->toBeNull();
    fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals')
        ->assertOk()
        ->assertJsonPath('body.data.0.line_id', $line->id);
});

/*
| US2: reassign, swap, unassign; guests in the house and past stays protected.
*/

it('reassigns a line and frees the old room for others at once', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(2, 3));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $this->rooms[1]->id]])->assertOk();

    $other = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    [$otherLine] = raLines($other);
    raPut($this, $this->admin, $other, [['line_id' => $otherLine->id, 'room_id' => $this->rooms[0]->id]])->assertOk();

    $event = EventLog::where('event_type', 'reservation_room.updated')->where('subject_id', $line->id)->latest('occurred_at')->firstOrFail();
    expect($line->fresh()->room_id)->toBe($this->rooms[1]->id)
        ->and(json_encode($event->changes))->toContain($this->rooms[1]->id);
});

it('swaps the rooms of two lines in one call', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id, $this->rooms[1]->id], raAt(2, 3));
    [$first, $second] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [
        ['line_id' => $first->id, 'room_id' => $this->rooms[1]->id],
        ['line_id' => $second->id, 'room_id' => $this->rooms[0]->id],
    ])->assertOk();

    expect($first->fresh()->room_id)->toBe($this->rooms[1]->id)
        ->and($second->fresh()->room_id)->toBe($this->rooms[0]->id);
});

it('unassigns a line, which still counts against its room type', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(2, 1));
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => null]])->assertOk();

    $night = now()->addDays(2)->toDateString();
    fdGet($this, $this->admin, '/api/availability?arrival_date='.$night.'&departure_date='.now()->addDays(3)->toDateString())
        ->assertOk()
        ->assertJsonPath('body.room_types.0.nights.0.booked', 1);
    expect($line->fresh()->room_id)->toBeNull();
});

it('will not change or clear the room of a guest in the house', function (bool $clear) {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id]);
    fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/check-in")->assertOk();
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation, [['line_id' => $line->id, 'room_id' => $clear ? null : $this->rooms[1]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => "Room {$this->rooms[0]->room_number} is checked in; move the guest with the room-move action."]);
})->with(['change' => false, 'clear' => true]);

it('will not move a guest in the house through the general reservation edit', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id]);
    fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/check-in")->assertOk();
    [$line] = raLines($reservation);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['rooms' => [['id' => $line->id, 'room_id' => $this->rooms[1]->id]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rooms' => "Room {$this->rooms[0]->room_number} is checked in; move the guest with the room-move action."]);
});

it('keeps the room of a stay that has departed or never came', function (string $action, string $status) {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id, $this->rooms[1]->id]);
    [$stay, $other] = fdStays($reservation);

    if ($action === 'check-out') {
        // Both in, one out: the reservation stays checked in, the room's stay departed.
        fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/check-in")->assertOk();
    }
    fdPost($this, $this->admin, "/api/stays/{$stay->id}/{$action}")->assertOk();
    [$line] = raLines($reservation);

    raPut($this, $this->admin, $reservation->fresh(), [['line_id' => $line->id, 'room_id' => $this->rooms[2]->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assignments.0.room_id' => "Room changes are closed for this room ({$status})."]);
})->with([
    'departed' => ['check-out', 'departed'],
    'no-show' => ['no-show', 'no_show'],
]);

/*
| Permissions (FR-019, FR-020).
*/

/*
| Review fixes (2026-11-01).
*/

it('reports a clash found by the general edit under the assignment it came from', function () {
    $reservation = fdBook($this->hotel, $this->type, [null, null], raAt(2, 3));
    [$first, $second] = raLines($reservation);

    // Both lines asking for one room is caught by the edit's own check.
    $errors = raPut($this, $this->admin, $reservation, [
        ['line_id' => $first->id, 'room_id' => $this->rooms[0]->id],
        ['line_id' => $second->id, 'room_id' => $this->rooms[0]->id],
    ])->assertUnprocessable()->json('errors');

    expect(array_keys($errors))->each->toStartWith('assignments.')
        ->and(array_keys($errors))->not->toContain('rooms');
});

it('extends the dates of a reservation whose departed room was sold again', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id, $this->rooms[1]->id]);
    fdPost($this, $this->admin, "/api/reservation/{$reservation->id}/check-in")->assertOk();
    [$early] = fdStays($reservation);
    fdPost($this, $this->admin, "/api/stays/{$early->id}/check-out")->assertOk();
    fdBook($this->hotel, $this->type, [$this->rooms[0]->id], ['arrival_date' => now()->addDay()->toDateString(), 'departure_date' => now()->addDays(4)->toDateString()]);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['departure_date' => now()->addDays(3)->toDateString()])
        ->assertOk();
});

it('deletes a reservation whose departed room was sold again', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id, $this->rooms[1]->id]);
    [$gone] = fdStays($reservation);
    fdPost($this, $this->admin, "/api/stays/{$gone->id}/no-show")->assertOk();
    fdBook($this->hotel, $this->type, [$this->rooms[0]->id]);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
        ->deleteJson("/api/reservation/{$reservation->id}")
        ->assertOk();

    expect(Reservation::withTrashed()->find($reservation->id)->trashed())->toBeTrue();
});

it('needs reservations.assign_room', function () {
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(2, 3));
    [$line] = raLines($reservation);
    $payload = [['line_id' => $line->id, 'room_id' => $this->rooms[0]->id]];

    raPut($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_UPDATE]), $reservation, $payload)->assertForbidden();
    raPut($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_ASSIGN_ROOM]), $reservation, $payload)->assertOk();
});

it('needs reservations.assign_room to set or change a room on the general edit, not to echo it', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id], raAt(2, 3));
    [$line] = raLines($reservation);
    $editor = fdEmployee($this->hotel, [Permission::RESERVATIONS_UPDATE]);
    $put = fn (array $rooms) => $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($editor, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['rooms' => $rooms]);

    $put([['id' => $line->id, 'room_id' => $this->rooms[0]->id]])->assertOk();
    $put([['id' => $line->id, 'room_id' => $this->rooms[1]->id]])->assertForbidden();
    $put([['id' => $line->id, 'room_id' => null]])->assertForbidden();
});

it('refuses the same clash through the general edit as through assignment', function () {
    fdBook($this->hotel, $this->type, [$this->rooms[0]->id], [...raAt(2, 3), 'reservation_id' => 'RES-HOLDER']);
    $reservation = fdBook($this->hotel, $this->type, [null], raAt(3, 1));
    [$line] = raLines($reservation);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($this->admin, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", ['rooms' => [['id' => $line->id, 'room_id' => $this->rooms[0]->id]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rooms' => "Room {$this->rooms[0]->room_number} is booked for RES-HOLDER on overlapping nights."]);

    expect(Room::find($this->rooms[0]->id))->not->toBeNull();
});
