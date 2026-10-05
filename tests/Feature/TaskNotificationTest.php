<?php

use App\Enums\UserRole;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AiTaskCreatedNotification;
use App\Notifications\HousekeepingRoutingNotification;
use App\Notifications\TaskAssignedNotification;
use App\Services\CreationNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/*
| Housekeeping and maintenance staff are told about their tasks, once per
| assignment (SPEC-030, User Story 6, FR-026–029).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Notification::fake();
});

/**
 * @return list<User>
 */
function teamMembers($hotel, string $teamId, int $count): array
{
    return collect(range(1, $count))
        ->map(fn () => User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'team_id' => $teamId]))
        ->all();
}

function noticesTo(User $user): int
{
    return Notification::sent($user, TaskAssignedNotification::class)->count();
}

it('tells every Housekeeping team member once about a check-out clean', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $members = teamMembers($hotel, $hotel->housekeeping_team_id, 2);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();

    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();

    foreach ($members as $member) {
        expect(noticesTo($member))->toBe(1);
    }
});

it('tells only the assignee when a person is assigned, and the new person on reassignment', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    [$first, $second] = teamMembers($hotel, $hotel->housekeeping_team_id, 2);
    Notification::fake();

    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $first->id])->assertOk();
    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $second->id])->assertOk();

    expect(noticesTo($first))->toBe(1)
        ->and(noticesTo($second))->toBe(1);
});

it('does not tell a team member again when the task is narrowed to them', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    [$member] = teamMembers($hotel, $hotel->housekeeping_team_id, 1);
    [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();
    $task = Task::where('room_id', $room->id)->sole();

    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $member->id])->assertOk();

    expect(noticesTo($member))->toBe(1);
});

it('sends nothing when a task is saved without changing its assignee', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    [$member] = teamMembers($hotel, $hotel->housekeeping_team_id, 1);
    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $member->id])->assertOk();

    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $member->id, 'title' => 'Renamed'])->assertOk();

    expect(noticesTo($member))->toBe(1);
});

it('tells a person again when a task comes back to them', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    [$first, $second] = teamMembers($hotel, $hotel->housekeeping_team_id, 2);

    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $first->id])->assertOk();
    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $second->id])->assertOk();
    hkPut($this, $hotel->owner, "/api/task/{$task->id}", ['assigned_to_user_id' => $first->id])->assertOk();

    expect(noticesTo($first))->toBe(2);
});

it('does not deliver a notice for a task that is already finished', function () {
    [$hotel, $room, $task] = hkVacatedRoom($this);
    [$member] = teamMembers($hotel, $hotel->housekeeping_team_id, 1);

    $notice = new TaskAssignedNotification($task);
    expect($notice->shouldSend($member, 'mail'))->toBeTrue();

    $task->update(['status' => 'completed']);
    expect($notice->shouldSend($member, 'mail'))->toBeFalse();
});

it('does not fan out to other teams', function () {
    [$hotel, $type, [$room]] = fdHotel(1);
    $frontOffice = Team::create(['hotel_id' => $hotel->id, 'name' => 'Front Office', 'is_active' => true]);
    [$member] = teamMembers($hotel, $frontOffice->id, 1);

    fdPost($this, $hotel->owner, '/api/task', ['hotel_id' => $hotel->id, 'title' => 'Welcome pack', 'assigned_to_team_id' => $frontOffice->id])->assertCreated();

    expect(noticesTo($member))->toBe(0);
});

it('alerts the admins once a day when an automatic task reaches a team with no members', function () {
    [$hotel, $type, $rooms] = fdHotel(2);

    foreach ($rooms as $room) {
        [$stay] = fdStays(fdBook($hotel, $type, [$room->id]));
        fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
        fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();
    }

    Notification::assertSentToTimes($hotel->owner, HousekeepingRoutingNotification::class, 1);
});

it('tells the Maintenance team about a reported issue', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    [$engineer] = teamMembers($hotel, $hotel->maintenance_team_id, 1);

    fdPost($this, $hotel->owner, "/api/task/{$cleaning->id}/issues", ['description' => 'Leak'])->assertCreated();

    expect(noticesTo($engineer))->toBe(1);
});

it('still tells the admins about a task the AI created', function () {
    [$hotel] = fdHotel(1);
    $task = Task::create(['hotel_id' => $hotel->id, 'title' => 'AI task', 'status' => 'pending']);

    app(CreationNotificationService::class)->taskCreated($task, createdByAi: true);

    Notification::assertSentTo($hotel->owner, AiTaskCreatedNotification::class);
});
