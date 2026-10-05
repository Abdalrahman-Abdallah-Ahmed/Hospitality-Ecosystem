<?php

use App\Enums\CleaningReason;
use App\Enums\HousekeepingCause;
use App\Enums\HousekeepingStatusesEnum;
use App\Jobs\StartHousekeepingDayJob;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HousekeepingDayRun;
use App\Models\Room;
use App\Models\Task;
use App\Services\HousekeepingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The start of each hotel's housekeeping day: stay-over rooms become dirty
| with one cleaning task each (SPEC-030, User Story 3, FR-011–014). Replaces
| the overnight dirtying job.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * A guest checked in yesterday for `$nights` nights.
 *
 * @return array{0: Hotel, 1: Room}
 */
function stayOverRoom($test, int $nights = 3, string $timezone = 'UTC'): array
{
    [$hotel, $type, [$room]] = fdHotel(1, $timezone);
    $test->travelTo(now()->subDay()->startOfDay()->addHours(14));
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now($timezone)->toDateString(),
        'departure_date' => now($timezone)->addDays($nights)->toDateString(),
    ]);
    [$stay] = fdStays($reservation);
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    $test->travelBack();

    return [$hotel->fresh(), $room->fresh()];
}

function runStartOfDay(): void
{
    // The scheduler has nobody signed in.
    app('auth')->forgetGuards();
    (new StartHousekeepingDayJob)->handle(app(HousekeepingService::class));
}

it('dirties a stay-over room and gives it one stay-over cleaning task due today', function () {
    [$hotel, $room] = stayOverRoom($this);

    runStartOfDay();

    $task = Task::where('room_id', $room->id)->sole();
    $event = EventLog::where('subject_id', $room->id)->where('event_type', 'room.housekeeping_changed')->latest('id')->first();

    expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY)
        ->and($task->cleaning_reason)->toBe(CleaningReason::STAY_OVER)
        ->and($task->due_date->toDateString())->toBe(now()->toDateString())
        ->and($task->assigned_to_team_id)->toBe($hotel->housekeeping_team_id)
        ->and($event->changes['cause']['to'])->toBe('start_of_day')
        ->and($event->actor_kind->value)->toBe('system');
});

it('leaves a room departing today to check-out', function () {
    [, $room] = stayOverRoom($this, nights: 1);

    runStartOfDay();

    expect(Task::where('room_id', $room->id)->count())->toBe(0);
});

it('ignores rooms with no guest in them', function () {
    [$hotel, $type, [$expectedRoom, $emptyRoom]] = fdHotel(2);
    fdBook($hotel, $type, [$expectedRoom->id]);

    runStartOfDay();

    expect(Task::count())->toBe(0)
        ->and($expectedRoom->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN)
        ->and($emptyRoom->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('leaves an out-of-order room alone', function () {
    [, $room] = stayOverRoom($this);
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['status' => 'out_of_order', 'out_of_order_reason' => 'Leak']);

    runStartOfDay();

    expect(Task::where('room_id', $room->id)->count())->toBe(0)
        ->and($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('reuses an open cleaning task rather than adding a second', function () {
    [$hotel, $room] = stayOverRoom($this);
    app(HousekeepingService::class)->roomNeedsCleaning($room, CleaningReason::MANUAL, HousekeepingCause::MANUAL);

    runStartOfDay();

    expect(Task::where('room_id', $room->id)->count())->toBe(1);
});

it('runs once per hotel and day however often it fires', function () {
    [$hotel, $room] = stayOverRoom($this);

    runStartOfDay();
    Task::where('room_id', $room->id)->sole()->update(['status' => 'completed']);
    runStartOfDay();
    app(HousekeepingService::class)->startDay($hotel, now()->toDateString());

    expect(HousekeepingDayRun::where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and(Task::where('room_id', $room->id)->count())->toBe(1);
});

it('uses each hotel own local date', function () {
    Carbon::setTestNow('2026-10-10 22:30:00');
    [$dubai, $dubaiRoom] = stayOverRoom($this, timezone: 'Asia/Dubai');
    [$newYork, $nyRoom] = stayOverRoom($this, timezone: 'America/New_York');
    Carbon::setTestNow('2026-10-10 22:30:00');

    runStartOfDay();

    expect(HousekeepingDayRun::where('hotel_id', $dubai->id)->value('day')->toDateString())->toBe('2026-10-11')
        ->and(HousekeepingDayRun::where('hotel_id', $newYork->id)->value('day')->toDateString())->toBe('2026-10-10');
});

it('works on one hotel without touching another', function () {
    [$hotel, $room] = stayOverRoom($this);
    [$other, $otherRoom] = stayOverRoom($this);

    app(HousekeepingService::class)->startDay($hotel, now()->toDateString());

    expect(Task::where('room_id', $room->id)->count())->toBe(1)
        ->and(Task::where('room_id', $otherRoom->id)->count())->toBe(0)
        ->and($otherRoom->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::CLEAN);
});

it('skips an inactive hotel', function () {
    [$hotel, $room] = stayOverRoom($this);
    $hotel->update(['is_active' => false]);

    runStartOfDay();

    expect(Task::where('room_id', $room->id)->count())->toBe(0);
});

it('dirties every room of a multi-room stay', function () {
    [$hotel, $type, $rooms] = fdHotel(2);
    $this->travelTo(now()->subDay()->startOfDay()->addHours(14));
    $reservation = fdBook($hotel, $type, [$rooms[0]->id, $rooms[1]->id], ['departure_date' => now()->addDays(3)->toDateString()]);
    fdPost($this, $hotel->owner, "/api/reservation/{$reservation->id}/check-in")->assertOk();
    $this->travelBack();

    runStartOfDay();

    foreach ($rooms as $room) {
        expect($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
    }
    expect(Task::whereIn('room_id', [$rooms[0]->id, $rooms[1]->id])->count())->toBe(2);
});

it('cleans the room of a guest still in after their departure date', function () {
    [$hotel, $room] = stayOverRoom($this, nights: 1);
    $this->travel(1)->days();

    runStartOfDay();

    expect(Task::where('room_id', $room->id)->sole()->cleaning_reason)->toBe(CleaningReason::STAY_OVER)
        ->and($room->fresh()->housekeeping_status)->toBe(HousekeepingStatusesEnum::DIRTY);
});
