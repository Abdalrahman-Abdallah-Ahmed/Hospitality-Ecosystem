<?php

use App\Enums\GuestSignal;
use App\Enums\HousekeepingKind;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Jobs\SendGuestRequestNoticeJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\EventLog;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
| POST /api/stays/{stay}/move-room (spec 020 US4, FR-013, FR-014).
*/

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    [$this->hotel, $this->type, $this->rooms] = fdHotel(4);
    $this->admin = $this->hotel->owner;
    $this->reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id]);
    [$this->stay] = fdStays($this->reservation);
    fdPost($this, $this->admin, "/api/stays/{$this->stay->id}/check-in")->assertOk();
    $this->stay = $this->stay->fresh();
});

function rmMove($test, $user, $stay, array $payload)
{
    return fdPost($test, $user, "/api/stays/{$stay->id}/move-room", $payload);
}

it('moves the guest, frees and dirties the old room, and audits the reason', function () {
    $checkedInAt = $this->stay->checked_in_at;
    $old = $this->rooms[0];
    $new = $this->rooms[1];

    $response = rmMove($this, $this->admin, $this->stay, ['room_id' => $new->id, 'reason' => 'Air conditioning failed'])
        ->assertOk()
        ->assertJsonPath('body.vacated_room.room_number', $old->room_number)
        ->assertJsonPath('body.vacated_room.housekeeping_status', 'dirty');

    $stay = $this->stay->fresh();
    $cleaning = Task::where('room_id', $old->id)->where('housekeeping_kind', HousekeepingKind::CLEANING)->open()->sole();

    expect($stay->room_id)->toBe($new->id)
        ->and($stay->status->value)->toBe('in_house')
        ->and($stay->checked_in_at->equalTo($checkedInAt))->toBeTrue()
        ->and($stay->reservationRoom->room_id)->toBe($new->id)
        ->and($new->fresh()->status->value)->toBe('occupied')
        ->and($old->fresh()->status->value)->toBe('available')
        ->and($response->json('body.cleaning_task_id'))->toBe($cleaning->id);

    $event = EventLog::where('event_type', 'stay.room_moved')->where('subject_id', $stay->id)->sole();
    expect($event->reason)->toBe('Air conditioning failed')
        ->and($event->changes)->toBe(['from_room' => $old->room_number, 'to_room' => $new->room_number])
        ->and($event->actor_id)->toBe($this->admin->id);
});

it('refuses a target room that cannot take the guest', function (Closure $prepare, string $message) {
    $target = $this->rooms[1];
    $expected = $prepare->call($this, $target) ?? str_replace('{n}', $target->room_number, $message);

    rmMove($this, $this->admin, $this->stay, ['room_id' => $target->id, 'reason' => 'Noise'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_id' => $expected]);

    expect($this->stay->fresh()->room_id)->toBe($this->rooms[0]->id);
})->with([
    'out of order' => [function ($room) {
        $room->update(['status' => 'out_of_order', 'out_of_order_reason' => 'Leak']);

        return null;
    }, 'Room {n} is out of order.'],
    'booked by another guest on a night of the stay' => [function ($room) {
        fdBook($this->hotel, $this->type, [$room->id], ['arrival_date' => now()->addDay()->toDateString(), 'departure_date' => now()->addDays(3)->toDateString(), 'reservation_id' => 'RES-NEXT']);

        return null;
    }, 'Room {n} is booked for RES-NEXT on overlapping nights.'],
    'of another type' => [function ($room) {
        $room->update(['room_type_id' => avType($this->hotel, 'Suite')->id]);

        return null;
    }, "Room {n} is not of the line's room type; send upgrade to place it anyway."],
]);

it('refuses a room with a guest in it', function () {
    // Yesterday's guest is still in room 2 on their departure day.
    $earlier = fdBook($this->hotel, $this->type, [$this->rooms[1]->id], ['arrival_date' => now()->subDay()->toDateString(), 'departure_date' => now()->toDateString()]);
    $this->travel(-1)->days();
    fdPost($this, $this->admin, "/api/reservation/{$earlier->id}/check-in")->assertOk();
    $this->travelBack();

    // Today's stay is short enough that nothing else holds room 2.
    rmMove($this, $this->admin, $this->stay, ['room_id' => $this->rooms[1]->id, 'reason' => 'Noise'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_id' => "Room {$this->rooms[1]->room_number} is occupied."]);
    expect($this->stay->fresh()->room_id)->toBe($this->rooms[0]->id);
});

it('refuses the room the guest is already in', function () {
    rmMove($this, $this->admin, $this->stay, ['room_id' => $this->rooms[0]->id, 'reason' => 'Noise'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['room_id' => "The guest is already in room {$this->rooms[0]->room_number}."]);
});

it('only moves a guest who is in the house', function () {
    $later = fdBook($this->hotel, $this->type, [$this->rooms[2]->id], ['arrival_date' => now()->addDays(5)->toDateString(), 'departure_date' => now()->addDays(6)->toDateString()]);
    [$expected] = fdStays($later);

    rmMove($this, $this->admin, $expected, ['room_id' => $this->rooms[3]->id, 'reason' => 'Noise'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stay' => 'Only a guest in the house can be moved to another room.']);
});

it('needs a reason of at most 500 characters', function (?string $reason) {
    rmMove($this, $this->admin, $this->stay, ['room_id' => $this->rooms[1]->id, 'reason' => $reason])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);
})->with([null, '', str_repeat('x', 501)]);

it('points to the guest\'s open room-change request and leaves it open', function () {
    $task = Task::make([
        'hotel_id' => $this->hotel->id,
        'guest_id' => $this->stay->guest_id,
        'reservation_id' => $this->reservation->id,
        'stay_id' => $this->stay->id,
        'room_id' => $this->rooms[0]->id,
        'title' => 'Room change request',
        'description' => 'Too noisy',
        'created_by' => 'guest',
        'status' => TaskStatus::PENDING,
        'priority' => 'normal',
    ]);
    $task->guest_signal = GuestSignal::ROOM_CHANGE_REQUEST;
    $task->save();

    rmMove($this, $this->admin, $this->stay, ['room_id' => $this->rooms[1]->id, 'reason' => 'Too noisy'])
        ->assertOk()
        ->assertJsonPath('body.open_room_change_requests.0.id', $task->id);

    expect($task->fresh()->status)->toBe(TaskStatus::PENDING);
});

it('sends the guest nothing', function () {
    Queue::fake();

    rmMove($this, $this->admin, $this->stay, ['room_id' => $this->rooms[1]->id, 'reason' => 'Noise'])->assertOk();

    // Housekeeping hears about the cleaning task; nothing goes to the guest.
    Queue::assertNotPushed(SendWhatsAppMessageJob::class);
    Queue::assertNotPushed(SendGuestRequestNoticeJob::class);
});

it('needs reservations.move_room, and hides other hotels\' stays', function () {
    $payload = ['room_id' => $this->rooms[1]->id, 'reason' => 'Noise'];

    rmMove($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_ASSIGN_ROOM, Permission::STAYS_CHECK_IN]), $this->stay, $payload)->assertForbidden();

    [$other] = fdHotel(1);
    rmMove($this, $other->owner, $this->stay, $payload)->assertNotFound();

    rmMove($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_MOVE_ROOM]), $this->stay, $payload)->assertOk();
});
