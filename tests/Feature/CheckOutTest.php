<?php

use App\Enums\CreatedBy;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\StayStatus;
use App\Enums\TaskStatus;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Checking one room out (SPEC-025, spec User Story 2), and the hotel settings
| that say where the cleaning task goes (FR-013).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function checkOutUri(Stay $stay): string
{
    return "/api/stays/{$stay->id}/check-out";
}

/**
 * A booking whose one room is checked in, `$daysAgo` days ago.
 *
 * @return array{0: Hotel, 1: Stay, 2: Room}
 */
function inHouseStay($test, int $daysAgo = 1, int $nightsLeft = 1): array
{
    [$hotel, $type, [$room]] = fdHotel();
    $test->travelTo(now()->subDays($daysAgo)->startOfDay()->addHours(14));
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now()->toDateString(),
        'departure_date' => now()->addDays($daysAgo + $nightsLeft)->toDateString(),
    ]);
    [$stay] = fdStays($reservation);
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    $test->travelBack();
    $test->travelTo(now()->startOfDay()->addHours(11));

    return [$hotel, $stay->fresh(), $room->fresh()];
}

/**
 * @return array{0: Team, 1: TaskCategory}
 */
function housekeepingDefaults(Hotel $hotel, string $teamName = 'Housekeeping'): array
{
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => $teamName, 'is_active' => true]);
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'name' => 'تنظيف']);
    $hotel->update(['housekeeping_team_id' => $team->id, 'cleaning_task_category_id' => $category->id]);

    return [$team, $category];
}

it('checks a guest out: stay departed with actual nights, room free and dirty, reservation checked out', function () {
    [$hotel, $stay, $room] = inHouseStay($this, daysAgo: 2);

    fdPost($this, $hotel->owner, checkOutUri($stay))
        ->assertOk()
        ->assertJsonPath('body.reservation.status', 'checked_out')
        ->assertJsonPath('body.stays.0.status', 'departed');

    $stay->refresh();
    expect($stay->status)->toBe(StayStatus::DEPARTED)
        ->and($stay->nights)->toBe(2)
        ->and($room->fresh()->status)->toBe('available')
        ->and($room->fresh()->housekeeping_status->value)->toBe('dirty')
        ->and(EventLog::where('event_type', 'stay.checked_out')->where('subject_id', $stay->id)->sole()->actor_id)->toBe($hotel->owner->id)
        ->and(Transaction::withoutGlobalScope('hotel')->count())->toBe(0);
});

it('creates one cleaning task for the room, sent to the hotel\'s chosen team and category, even with an Arabic team name', function () {
    [$hotel, $stay, $room] = inHouseStay($this);
    [$team, $category] = housekeepingDefaults($hotel, 'التدبير المنزلي');

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk()->assertJsonCount(1, 'body.cleaning_tasks');

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->created_by)->toBe(CreatedBy::SYSTEM)
        ->and($task->status)->toBe(TaskStatus::PENDING)
        ->and($task->priority)->toBe(Priority::NORMAL)
        ->and($task->title)->toBe("Clean room {$room->room_number} after check-out")
        ->and([$task->room_id, $task->reservation_id, $task->guest_id, $task->stay_id])
        ->toBe([$room->id, $stay->reservation_id, $stay->guest_id, $stay->id])
        ->and($task->assigned_to_team_id)->toBe($team->id)
        ->and($task->task_category_id)->toBe($category->id);
});

it('still checks out and leaves the cleaning task unassigned when no team is set, or the chosen one is inactive', function (bool $setThenDeactivate) {
    [$hotel, $stay] = inHouseStay($this);

    if ($setThenDeactivate) {
        [$team] = housekeepingDefaults($hotel);
        $team->update(['is_active' => false]);
    }

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();

    $task = Task::withoutGlobalScope('hotel')->sole();
    expect($task->assigned_to_team_id)->toBeNull()
        ->and($stay->fresh()->status)->toBe(StayStatus::DEPARTED);
})->with(['none set' => [false], 'team deactivated' => [true]]);

it('counts the nights actually stayed when a guest leaves early, and frees the rest', function () {
    [$hotel, $stay] = inHouseStay($this, daysAgo: 1, nightsLeft: 3);
    $type = $stay->reservationRoom->roomType;

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();

    expect($stay->fresh()->nights)->toBe(1);

    $grid = app(AvailabilityService::class)->forHotel($hotel, now()->toDateString(), now()->addDays(3)->toDateString(), [$type->id]);
    expect($grid['room_types'][0]['nights'])->toHaveCount(3)
        ->and(collect($grid['room_types'][0]['nights'])->sum('booked'))->toBe(0);
});

it('checks out a guest who stayed past their departure date', function () {
    [$hotel, $stay] = inHouseStay($this, daysAgo: 3, nightsLeft: 0);
    $this->travel(1)->days();

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();

    expect($stay->fresh()->nights)->toBe(4);
});

it('refuses a stay that never checked in', function () {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $hotel->owner, checkOutUri($stay))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stay->id}.stay_status" => 'This room is expected.']);
});

it('does nothing the second time: no second task, no second audit row', function () {
    [$hotel, $stay] = inHouseStay($this);

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();
    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk()->assertJsonCount(0, 'body.cleaning_tasks');

    expect(Task::withoutGlobalScope('hotel')->count())->toBe(1)
        ->and(EventLog::where('event_type', 'stay.checked_out')->count())->toBe(1);
});

it('keeps an out-of-order room out of order, marks it dirty and still creates the task', function () {
    [$hotel, $stay, $room] = inHouseStay($this);
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['status' => 'maintenance']);

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();

    expect($room->fresh()->status)->toBe('maintenance')
        ->and($room->fresh()->housekeeping_status->value)->toBe('dirty')
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1);
});

it('leaves a blocked room blocked', function () {
    [$hotel, $stay, $room] = inHouseStay($this);
    Room::withoutGlobalScope('hotel')->whereKey($room->id)->update(['housekeeping_status' => 'blocked']);

    fdPost($this, $hotel->owner, checkOutUri($stay))->assertOk();

    expect($room->fresh()->housekeeping_status->value)->toBe('blocked');
});

it('records an earlier actual time from today, with the entry time on the same audit row', function () {
    [$hotel, $stay] = inHouseStay($this);
    $earlier = now()->startOfDay()->addHours(7)->toIso8601String();

    fdPost($this, $hotel->owner, checkOutUri($stay), ['checked_out_at' => $earlier])->assertOk();

    expect($stay->fresh()->checked_out_at->toIso8601String())->toBe($earlier);
    $audit = EventLog::where('event_type', 'stay.checked_out')->sole();
    expect($audit->changes)->toHaveKey('entered_at');
});

it('refuses a check-out time from yesterday, the future, or before the check-in', function (string $when) {
    [$hotel, $stay] = inHouseStay($this, daysAgo: 0);
    $time = match ($when) {
        'yesterday' => now()->subDay()->toIso8601String(),
        'future' => now()->addHour()->toIso8601String(),
        'before check-in' => $stay->checked_in_at->copy()->subMinute()->toIso8601String(),
    };

    fdPost($this, $hotel->owner, checkOutUri($stay), ['checked_out_at' => $time])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['checked_out_at']);

    expect($stay->fresh()->status)->toBe(StayStatus::IN_HOUSE);
})->with(['yesterday', 'future', 'before check-in']);

it('lets an admin choose the housekeeping team and cleaning category, and checks them', function () {
    [$hotel] = fdHotel();
    [$otherHotel] = fdHotel();
    $team = Team::create(['hotel_id' => $hotel->id, 'name' => 'Housekeeping', 'is_active' => true]);
    $category = TaskCategory::create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'name' => 'Cleaning']);
    $inactive = Team::create(['hotel_id' => $hotel->id, 'name' => 'Old', 'is_active' => false]);
    $otherTeam = Team::create(['hotel_id' => $hotel->id, 'name' => 'Maintenance', 'is_active' => true]);
    $foreignTeam = Team::create(['hotel_id' => $otherHotel->id, 'name' => 'Theirs', 'is_active' => true]);
    $put = fn ($user, array $payload) => $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->putJson("/api/hotel/{$hotel->id}", $payload);

    $put($hotel->owner, ['housekeeping_team_id' => $team->id, 'cleaning_task_category_id' => $category->id])
        ->assertOk()
        ->assertJsonPath('body.housekeeping_team_id', $team->id)
        ->assertJsonPath('body.cleaning_task_category_id', $category->id);

    $put($hotel->owner, ['housekeeping_team_id' => $foreignTeam->id])->assertForbidden();
    $put($hotel->owner, ['housekeeping_team_id' => $inactive->id, 'cleaning_task_category_id' => null])->assertUnprocessable();
    $put($hotel->owner, ['housekeeping_team_id' => $otherTeam->id])->assertUnprocessable();
    $put(fdEmployee($hotel, Permission::cases()), ['housekeeping_team_id' => null])->assertForbidden();

    expect($hotel->fresh()->housekeeping_team_id)->toBe($team->id);
});
