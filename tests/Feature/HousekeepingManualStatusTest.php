<?php

use App\Enums\HousekeepingStatusesEnum;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\EventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Staff correct a room's housekeeping status by hand (SPEC-030, User Story 8,
| FR-009, FR-010).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function manualUri($room): string
{
    return "/api/room/{$room->id}/housekeeping-status";
}

it('sets the status with a reason, audits it, and lists the open task without touching it', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);

    hkPut($this, $hotel->owner, manualUri($room), ['housekeeping_status' => 'clean', 'reason' => 'Cleaned without a task'])
        ->assertOk()
        ->assertJsonPath('body.changed', true)
        ->assertJsonPath('body.room.housekeeping_status', 'clean')
        ->assertJsonPath('body.open_housekeeping_tasks.0.id', $task->id);

    $event = EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->latest('id')->first();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and($task->fresh()->status)->toBe(TaskStatus::PENDING)
        ->and($event->changes['cause']['to'])->toBe('manual')
        ->and($event->changes['reason']['to'])->toBe('Cleaned without a task')
        ->and($event->actor_id)->toBe($hotel->owner->id);
});

it('requires a reason and a known status', function (array $payload) {
    [$hotel, $type, [$room]] = fdHotel(1);

    hkPut($this, $hotel->owner, manualUri($room), $payload)->assertUnprocessable();
})->with([
    'no reason' => [['housekeeping_status' => 'dirty']],
    'old blocked value' => [['housekeeping_status' => 'blocked', 'reason' => 'x']],
]);

it('writes no audit entry when the status is unchanged', function () {
    [$hotel, $type, [$room]] = fdHotel(1);

    hkPut($this, $hotel->owner, manualUri($room), ['housekeeping_status' => 'clean', 'reason' => 'Check'])
        ->assertOk()->assertJsonPath('body.changed', false);

    expect(EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->count())->toBe(0);
});

it('needs rooms.update_housekeeping_status', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $payload = ['housekeeping_status' => 'dirty', 'reason' => 'Spill'];

    hkPut($this, fdEmployee($hotel, [Permission::ROOMS_UPDATE]), manualUri($room), $payload)->assertForbidden();
    hkPut($this, fdEmployee($hotel, [Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS]), manualUri($room), $payload)->assertOk();
});
