<?php

use App\Enums\Permission;
use App\Enums\RoomStatusesEnum;
use App\Models\Room;
use App\Models\Task;
use App\Services\HousekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| A room issue found on a housekeeping task becomes a maintenance task, and
| can take the room out of order (SPEC-035, User Story 4, FR-022–025).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function issueUri(Task $task): string
{
    return "/api/task/{$task->id}/issues";
}

it('creates one maintenance task for the room, linked to the cleaning task and its reporter', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);

    $response = fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Shower leaking at the base', 'priority' => 'high'])
        ->assertCreated()
        ->assertJsonPath('body.created', true)
        ->assertJsonPath('body.out_of_order.requested', false);

    $task = Task::find($response->json('body.maintenance_task.id'));

    expect($task->room_id)->toBe($room->id)
        ->and($task->source_task_id)->toBe($cleaning->id)
        ->and($task->created_by_user_id)->toBe($hotel->owner->id)
        ->and($task->assigned_to_team_id)->toBe($hotel->maintenance_team_id)
        ->and($task->task_category_id)->toBe($hotel->maintenance_task_category_id)
        ->and($task->priority->value)->toBe('high')
        ->and($task->description)->toBe('Shower leaking at the base');
});

it('takes the room out of order when it cannot be sold and the reporter may do that', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);

    $response = fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Ceiling leak', 'room_unsellable' => true])
        ->assertCreated()
        ->assertJsonPath('body.out_of_order.applied', true);

    $room->refresh();
    expect($room->status)->toBe(RoomStatusesEnum::OUT_OF_ORDER)
        ->and($room->out_of_order_reason)->toBe('Ceiling leak')
        ->and($room->out_of_order_task_id)->toBe($response->json('body.maintenance_task.id'));
});

it('creates the task but leaves the room on sale when the reporter cannot take rooms out of order', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    $housekeeper = fdEmployee($hotel, [Permission::TASKS_UPDATE]);

    fdPost($this, $housekeeper, issueUri($cleaning), ['description' => 'Broken lamp', 'room_unsellable' => true])
        ->assertCreated()
        ->assertJsonPath('body.out_of_order.applied', false)
        ->assertJsonPath('body.out_of_order.reason', fn (string $reason) => str_contains($reason, 'permission'));

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::AVAILABLE)
        ->and(Task::whereNotNull('source_task_id')->count())->toBe(1);
});

it('creates the task but leaves an occupied room occupied', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    $cleaning = Task::create([
        'hotel_id' => $hotel->id, 'room_id' => $room->id, 'title' => 'Stay-over clean', 'status' => 'in_progress',
        'task_category_id' => $hotel->cleaning_task_category_id, 'assigned_to_team_id' => $hotel->housekeeping_team_id,
    ]);
    app(HousekeepingService::class)->taskCreated($cleaning);

    fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Toilet blocked', 'room_unsellable' => true])
        ->assertCreated()
        ->assertJsonPath('body.out_of_order.applied', false)
        ->assertJsonPath('body.out_of_order.reason', fn (string $reason) => str_contains($reason, 'in-house guest'));

    expect($room->fresh()->status)->toBe(RoomStatusesEnum::OCCUPIED);
});

it('returns the same task when the same report is sent twice', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);

    $first = fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Shower leaking'])->assertCreated();
    fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => '  shower LEAKING '])
        ->assertOk()
        ->assertJsonPath('body.created', false)
        ->assertJsonPath('body.maintenance_task.id', $first->json('body.maintenance_task.id'));

    expect(Task::whereNotNull('source_task_id')->count())->toBe(1);
});

it('accepts a report on a clean completed today, and refuses one completed yesterday', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();

    fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Scratched desk'])->assertCreated();

    $this->travel(1)->days();

    fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Another thing'])->assertUnprocessable();
});

it('refuses a report on a task that is not housekeeping, or has no room', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $other = Task::create(['hotel_id' => $hotel->id, 'room_id' => $room->id, 'title' => 'Order flowers', 'status' => 'pending']);
    $noRoom = Task::create([
        'hotel_id' => $hotel->id, 'title' => 'Clean the lobby', 'status' => 'pending',
        'task_category_id' => $hotel->cleaning_task_category_id, 'assigned_to_team_id' => $hotel->housekeeping_team_id,
    ]);

    fdPost($this, $hotel->owner, issueUri($other), ['description' => 'x'])->assertUnprocessable();
    fdPost($this, $hotel->owner, issueUri($noRoom), ['description' => 'x'])->assertUnprocessable();
});

it('still creates the task, unassigned, when the hotel has no maintenance defaults', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    $hotel->update(['maintenance_team_id' => null, 'maintenance_task_category_id' => null]);

    $response = fdPost($this, $hotel->owner, issueUri($cleaning), ['description' => 'Broken window'])->assertCreated();

    expect($response->json('body.maintenance_task.assigned_to_team_id'))->toBeNull();
});

it('lets a housekeeper with tasks.update report, and refuses one without it', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);

    fdPost($this, fdEmployee($hotel, [Permission::TASKS_VIEW]), issueUri($cleaning), ['description' => 'x'])->assertForbidden();
    fdPost($this, fdEmployee($hotel, [Permission::TASKS_UPDATE]), issueUri($cleaning), ['description' => 'x'])->assertCreated();

    expect(Room::withoutGlobalScope('hotel')->find($room->id)->status)->toBe(RoomStatusesEnum::AVAILABLE);
});
