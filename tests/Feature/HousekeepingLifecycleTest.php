<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Enums\CleaningReason;
use App\Enums\HousekeepingCause;
use App\Enums\HousekeepingKind;
use App\Enums\HousekeepingStatusesEnum;
use App\Enums\RoomStatusesEnum;
use App\Enums\TaskStatus;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request;

/*
| Cleaning tasks drive a room dirty → cleaning → clean (SPEC-030, User
| Story 1, FR-001–003, FR-007, FR-008, FR-010, FR-015).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function housekeepingEvents(Room $room): int
{
    return EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->count();
}

it('creates a check-out cleaning task in the hotel cleaning category for the Housekeeping team', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);

    expect($room->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
        ->and($task->housekeeping_kind)->toBe(HousekeepingKind::CLEANING)
        ->and($task->cleaning_reason)->toBe(CleaningReason::CHECK_OUT)
        ->and($task->task_category_id)->toBe($hotel->cleaning_task_category_id)
        ->and($task->assigned_to_team_id)->toBe($hotel->housekeeping_team_id);
});

it('moves the room to cleaning when the task starts and to clean when it is completed, auditing each change', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    $before = housekeepingEvents($room);

    hkSetTaskStatus($this, $hotel->owner, $task, 'in_progress')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEANING);

    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and(housekeepingEvents($room) - $before)->toBe(2);

    $event = EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->latest('occurred_at')->latest('id')->get()
        ->first(fn (EventLog $log) => ($log->changes['housekeeping_status']['to'] ?? null) === 'clean');

    expect($event->changes['cause']['to'])->toBe('task')
        ->and($event->changes['task_id']['to'])->toBe($task->id)
        ->and($event->actor_id)->toBe($hotel->owner->id);
});

it('sends the room back to dirty when an in-progress clean is cancelled or deleted', function (string $how) {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $task, 'in_progress')->assertOk();

    $how === 'cancel'
        ? hkSetTaskStatus($this, $hotel->owner, $task, 'cancelled')->assertOk()
        : $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')->deleteJson("/api/task/{$task->id}")->assertOk();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
})->with(['cancel', 'delete']);

it('changes the room once when the same completion is sent twice', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    $before = housekeepingEvents($room);

    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();
    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();

    expect(housekeepingEvents($room) - $before)->toBe(1);
});

it('cleans an occupied room without changing its room status', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    $task = Task::create([
        'hotel_id' => $hotel->id, 'room_id' => $room->id, 'title' => 'Clean', 'status' => 'pending',
        'task_category_id' => $hotel->cleaning_task_category_id, 'assigned_to_team_id' => $hotel->housekeeping_team_id,
    ]);
    app(HousekeepingService::class)->taskCreated($task);
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['housekeeping_status' => 'dirty']);

    hkSetTaskStatus($this, $hotel->owner, $task->fresh(), 'completed')->assertOk();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and($room->fresh()->status)->toBe(RoomStatusesEnum::OCCUPIED);
});

it('refuses a second open cleaning task for the same room, naming the open one', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);

    hkPost($this, $hotel->owner, '/api/task', ['hotel_id' => $hotel->id,
        'title' => 'Another clean',
        'room_id' => $room->id,
        'task_category_id' => $hotel->cleaning_task_category_id,
    ])->assertUnprocessable()->assertJsonPath('message', fn (string $message) => str_contains($message, $task->id));

    expect(Task::where('room_id', $room->id)->count())->toBe(1);
});

it('moves a reopened completed clean back to cleaning or dirty', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();

    hkSetTaskStatus($this, $hotel->owner, $task, 'in_progress')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEANING);

    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();
    hkSetTaskStatus($this, $hotel->owner, $task, 'pending')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('does not move the room when a stale completed task is cancelled', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $task, 'completed')->assertOk();

    app(HousekeepingService::class)->roomNeedsCleaning($room, CleaningReason::STAY_OVER, HousekeepingCause::START_OF_DAY);
    $newTask = Task::where('room_id', $room->id)->open()->sole();
    hkSetTaskStatus($this, $hotel->owner, $newTask, 'in_progress')->assertOk();

    hkSetTaskStatus($this, $hotel->owner, $task, 'cancelled')->assertOk();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEANING);
});

it('never moves a room for a task in another housekeeping category', function () {
    [$hotel, $room] = hkVacatedRoom($this);
    $turndown = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->housekeeping_team_id, 'name' => 'Turndown']);

    $response = hkPost($this, $hotel->owner, '/api/task', ['hotel_id' => $hotel->id,
        'title' => 'Turndown', 'room_id' => $room->id, 'task_category_id' => $turndown->id,
    ])->assertCreated();

    hkSetTaskStatus($this, $hotel->owner, Task::find($response->json('body.id')), 'in_progress')->assertOk();

    expect($response->json('body.housekeeping_kind'))->toBeNull()
        ->and($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('classifies a task moved into the cleaning category and only acts on later changes', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $other = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $hotel->housekeeping_team_id, 'name' => 'Linen']);

    $id = hkPost($this, $hotel->owner, '/api/task', ['hotel_id' => $hotel->id, 'title' => 'Linen', 'room_id' => $room->id, 'task_category_id' => $other->id])
        ->assertCreated()->json('body.id');
    hkSetTaskStatus($this, $hotel->owner, Task::find($id), 'in_progress')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);

    hkPut($this, $hotel->owner, "/api/task/{$id}", ['task_category_id' => $hotel->cleaning_task_category_id])
        ->assertOk()->assertJsonPath('body.housekeeping_kind', 'cleaning');
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);

    hkSetTaskStatus($this, $hotel->owner, Task::find($id), 'completed')->assertOk();
    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('assigns a task to its category team when no team is chosen', function () {
    [$hotel, $type, [$room]] = fdHotel(1);

    hkPost($this, $hotel->owner, '/api/task', ['hotel_id' => $hotel->id,
        'title' => 'Fix the lamp', 'room_id' => $room->id, 'task_category_id' => $hotel->maintenance_task_category_id,
    ])->assertCreated()->assertJsonPath('body.assigned_to_team_id', $hotel->maintenance_team_id);
});

it('reuses an open stay-over task as the check-out clean', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $this->travelTo(now()->subDay()->startOfDay()->addHours(14));
    $reservation = fdBook($hotel, $type, [$room->id], ['arrival_date' => now()->toDateString(), 'departure_date' => now()->addDays(2)->toDateString()]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    $this->travelBack();
    $this->travelTo(now()->startOfDay()->addHours(1));

    app(HousekeepingService::class)->startDay(Hotel::find($hotel->id), now()->toDateString());
    $stayOver = Task::where('room_id', $room->id)->sole();
    expect($stayOver->cleaning_reason)->toBe(CleaningReason::STAY_OVER);

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")
        ->assertOk()
        ->assertJsonPath('body.cleaning_tasks.0.id', $stayOver->id)
        ->assertJsonPath('body.cleaning_tasks.0.cleaning_reason', 'check_out');

    expect(Task::where('room_id', $room->id)->count())->toBe(1)
        ->and($stayOver->fresh()->status)->toBe(TaskStatus::PENDING);
});

function hkPost($test, User $user, string $uri, array $payload = []): TestResponse
{
    return fdPost($test, $user, $uri, $payload);
}

it('releases the room when an in-progress clean is moved to another category', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    hkSetTaskStatus($this, $hotel->owner, $task, 'in_progress')->assertOk();

    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['task_category_id' => $hotel->maintenance_task_category_id, 'assigned_to_team_id' => $hotel->maintenance_team_id])
        ->assertOk()
        ->assertJsonPath('body.housekeeping_kind', null);

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});

it('tells a guest a clean is already scheduled instead of creating a second one', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    app(HousekeepingService::class)->roomNeedsCleaning($room, CleaningReason::STAY_OVER, HousekeepingCause::START_OF_DAY);
    $open = Task::where('room_id', $room->id)->sole();

    $reply = (string) (new CreateGuestServiceRequestTool($reservation->guest, Hotel::find($hotel->id), $reservation))
        ->handle(new Request([
            'title' => 'Clean my room', 'description' => 'Please clean the room',
            'task_category_id' => $hotel->cleaning_task_category_id, 'kind' => 'service_request',
        ]));

    expect($reply)->toContain($open->id)
        ->and(Task::where('room_id', $room->id)->count())->toBe(1);
});
