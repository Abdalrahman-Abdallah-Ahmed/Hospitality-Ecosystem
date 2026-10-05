<?php

use App\Enums\CleaningReason;
use App\Enums\HousekeepingKind;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\InspectionResult;
use App\Models\EventLog;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Inspection after cleaning, per hotel (SPEC-030, User Story 2, FR-004–006).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function turnInspectionOn($test, $hotel): void
{
    hkPut($test, $hotel->owner, "/api/hotel/{$hotel->id}", ['inspection_required' => true])->assertOk();
}

function inspectionTaskFor($room): ?Task
{
    return Task::where('room_id', $room->id)->where('housekeeping_kind', HousekeepingKind::INSPECTION)->open()->first();
}

it('creates one inspection task when a clean is completed and the hotel inspects', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);

    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();

    $inspection = inspectionTaskFor($room);
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and($inspection)->not->toBeNull()
        ->and($inspection->task_category_id)->toBe($hotel->fresh()->inspection_task_category_id)
        ->and($inspection->assigned_to_team_id)->toBe($hotel->housekeeping_team_id);

    fdGet($this, $hotel->owner, "/api/room/{$room->id}")->assertJsonPath('body.ready', false);
});

it('makes the room inspected and ready on a pass', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();

    fdPost($this, $hotel->owner, '/api/task/'.inspectionTaskFor($room)->id.'/inspection', ['result' => 'pass'])
        ->assertOk()
        ->assertJsonPath('body.room.housekeeping_status', 'inspected')
        ->assertJsonPath('body.room.ready', true)
        ->assertJsonPath('body.task.inspection_result', 'pass');
});

it('sends the room back to dirty with a re-clean carrying the note on a fail', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $inspection = inspectionTaskFor($room);

    fdPost($this, $hotel->owner, "/api/task/{$inspection->id}/inspection", ['result' => 'fail'])
        ->assertUnprocessable()->assertJsonValidationErrors(['note']);

    fdPost($this, $hotel->owner, "/api/task/{$inspection->id}/inspection", ['result' => 'fail', 'note' => 'Bin not emptied'])
        ->assertOk()
        ->assertJsonPath('body.room.housekeeping_status', 'dirty')
        ->assertJsonPath('body.cleaning_task.cleaning_reason', 're_clean');

    $reclean = Task::where('room_id', $room->id)->where('cleaning_reason', CleaningReason::RE_CLEAN)->sole();
    expect($reclean->description)->toContain('Bin not emptied')
        ->and($inspection->fresh()->inspection_result)->toBe(InspectionResult::FAIL)
        ->and($inspection->fresh()->inspection_note)->toBe('Bin not emptied');
});

it('refuses to complete an inspection through the task edit, and leaves the room clean when one is cancelled', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $inspection = inspectionTaskFor($room);

    hkSetTaskStatus($this, $hotel->owner, $inspection, 'completed')->assertUnprocessable();
    hkSetTaskStatus($this, $hotel->owner, $inspection, 'cancelled')->assertOk();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('creates no inspection when the hotel does not inspect, and clean is ready', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);

    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();

    expect(inspectionTaskFor($room))->toBeNull();
    fdGet($this, $hotel->owner, "/api/room/{$room->id}")->assertJsonPath('body.ready', true);
});

it('keeps open inspections working after inspection is turned off', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $inspection = inspectionTaskFor($room);

    hkPut($this, $hotel->owner, "/api/hotel/{$hotel->id}", ['inspection_required' => false])->assertOk();

    fdPost($this, $hotel->owner, "/api/task/{$inspection->id}/inspection", ['result' => 'pass'])->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::INSPECTED);
});

it('counts a room cleaned before inspection was turned on as ready', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $this->travel(1)->minutes();

    turnInspectionOn($this, $hotel);

    fdGet($this, $hotel->owner, "/api/room/{$room->id}")->assertJsonPath('body.ready', true);
});

it('changes nothing when a pass is sent twice', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $inspection = inspectionTaskFor($room);

    fdPost($this, $hotel->owner, "/api/task/{$inspection->id}/inspection", ['result' => 'pass'])->assertOk();
    fdPost($this, $hotel->owner, "/api/task/{$inspection->id}/inspection", ['result' => 'pass'])->assertOk();

    expect(EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')
        ->get()->filter(fn ($log) => ($log->changes['housekeeping_status']['to'] ?? null) === 'inspected'))->toHaveCount(1);
});

it('warns at check-in that a clean but uninspected room is not ready', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    turnInspectionOn($this, $hotel);
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
    $type = $room->roomType;
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")
        ->assertOk()
        ->assertJsonPath('body.warnings.0.message', "Room {$room->room_number} is not ready (clean).");
});
