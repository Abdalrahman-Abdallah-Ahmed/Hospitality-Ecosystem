<?php

use App\Enums\CleaningReason;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\Permission;
use App\Enums\RoomStatusesEnum;
use App\Models\EventLog;
use App\Models\Room;
use App\Models\Task;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Taking a room out of order and returning it to service (SPEC-033, User
| Story 5, FR-016–021).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function sellableTonight($hotel, $type): int
{
    $grid = app(AvailabilityService::class)->forHotel($hotel, now()->toDateString(), now()->addDay()->toDateString(), [$type->id]);

    return $grid['room_types'][0]['nights'][0]['sellable'];
}

it('takes a room out of order with its reason, and availability drops at once', function () {
    [$hotel, $type, [$room]] = fdHotel(2);
    $before = sellableTonight($hotel, $type);

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", [
        'reason' => 'AC broken',
        'expected_end_date' => now()->addDays(2)->toDateString(),
    ])->assertOk()
        ->assertJsonPath('body.changed', true)
        ->assertJsonPath('body.room.status', 'out_of_order')
        ->assertJsonPath('body.room.out_of_order.reason', 'AC broken')
        ->assertJsonPath('body.room.out_of_order.expected_end_date', now()->addDays(2)->toDateString());

    $room->refresh();
    expect($room->status)->toBe(RoomStatusesEnum::OUT_OF_ORDER)
        ->and($room->out_of_order_by_user_id)->toBe($hotel->owner->id)
        ->and(sellableTonight($hotel, $type))->toBe($before - 1)
        ->and(EventLog::where('subject_id', $room->id)->where('event_type', 'room.taken_out_of_order')->count())->toBe(1);
});

it('refuses a room with a guest in it, naming the stay', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $message) => str_contains($message, $stay->id));

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::OCCUPIED);
});

it('lists the future reservation lines the room is assigned to', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now()->addDays(3)->toDateString(),
        'departure_date' => now()->addDays(5)->toDateString(),
    ]);

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])
        ->assertOk()
        ->assertJsonCount(1, 'body.affected_lines')
        ->assertJsonPath('body.affected_lines.0.reservation_id', $reservation->id);
});

it('changes nothing the second time', function () {
    [$hotel, $type, [$room]] = fdHotel(1);

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])
        ->assertOk()->assertJsonPath('body.changed', false);

    expect(EventLog::where('subject_id', $room->id)->where('event_type', 'room.taken_out_of_order')->count())->toBe(1);
});

it('rejects an expected end date in the past and a task from another hotel', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$otherHotel] = fdHotel(1);
    $foreign = Task::withoutGlobalScope('hotel')->create(['hotel_id' => $otherHotel->id, 'title' => 'Theirs', 'status' => 'pending']);

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak', 'expected_end_date' => now()->subDay()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['expected_end_date']);
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak', 'task_id' => $foreign->id])
        ->assertForbidden();

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::AVAILABLE);
});

it('updates the reason and expected end date, but only of an out-of-order room', function () {
    [$hotel, $type, [$room, $other]] = fdHotel(2);
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();

    hkPatch($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Compressor on order', 'expected_end_date' => now()->addDays(4)->toDateString()])
        ->assertOk()
        ->assertJsonPath('body.room.out_of_order.reason', 'Compressor on order');
    hkPatch($this, $hotel->owner, "/api/room/{$other->id}/out-of-order", ['reason' => 'x'])->assertUnprocessable();

    expect(EventLog::where('subject_id', $room->id)->where('event_type', 'room.out_of_order_updated')->count())->toBe(1);
});

it('keeps the room out of order when its repair is done, and says it can return', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $repair = Task::create(['hotel_id' => $hotel->id, 'room_id' => $room->id, 'title' => 'Fix AC', 'status' => 'pending']);
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'AC', 'task_id' => $repair->id])->assertOk();

    hkSetTaskStatus($this, $hotel->owner, $repair, 'completed')
        ->assertOk()
        ->assertJsonPath('body.room_ready_to_return', true);

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::OUT_OF_ORDER);
});

it('returns a room to service as available and dirty with one cleaning task', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'AC'])->assertOk();

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/return-to-service", ['note' => 'Fixed'])
        ->assertOk()
        ->assertJsonPath('body.room.status', 'available')
        ->assertJsonPath('body.room.out_of_order', null)
        ->assertJsonPath('body.cleaning_task.cleaning_reason', 'return_to_service');

    $room->refresh();
    expect($room->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
        ->and($room->out_of_order_reason)->toBeNull()
        ->and(Task::where('room_id', $room->id)->where('cleaning_reason', CleaningReason::RETURN_TO_SERVICE)->count())->toBe(1)
        ->and(EventLog::where('subject_id', $room->id)->where('event_type', 'room.returned_to_service')->count())->toBe(1);

    fdPost($this, $hotel->owner, "/api/room/{$room->id}/return-to-service")->assertUnprocessable();
});

it('still refuses to check a guest into an out-of-order room', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertUnprocessable();
});

it('never puts an out-of-order room back on sale when a guest leaves it', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['status' => 'out_of_order', 'out_of_order_reason' => 'Flood']);

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::OUT_OF_ORDER)
        ->and($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('keeps an overdue expected end date as information only', function () {
    [$hotel, $type, [$room, $other]] = fdHotel(2);
    fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'AC', 'expected_end_date' => now()->addDay()->toDateString()])->assertOk();

    $this->travel(3)->days();

    expect(sellableTonight($hotel, $type))->toBe(1);
    fdGet($this, $hotel->owner, "/api/room/{$room->id}")->assertJsonPath('body.out_of_order.overdue', true);
});

it('lets only an employee with rooms.set_out_of_order take a room out of order', function () {
    [$hotel, $type, [$room]] = fdHotel(1);

    fdPost($this, fdEmployee($hotel, [Permission::ROOMS_UPDATE]), "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertForbidden();
    fdPost($this, fdEmployee($hotel, [Permission::ROOMS_SET_OUT_OF_ORDER]), "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
});
