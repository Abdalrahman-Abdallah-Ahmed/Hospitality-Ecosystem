<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The housekeeping board and the maintenance list (SPEC-030/033, User
| Story 7, FR-030, FR-031, SC-008).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('lists every room once under its status, with counts, readiness, departures and open tasks', function () {
    [$hotel, $type, [$occupied, $dirty, $broken]] = fdHotel(3);
    [$stay] = fdStays(fdBook($hotel, $type, [$occupied->id]));
    fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    Room::withoutGlobalScope('hotel')->whereKey($dirty->id)->update(['housekeeping_status' => 'dirty']);
    fdPost($this, $hotel->owner, "/api/room/{$broken->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
    $task = Task::create(['hotel_id' => $hotel->id, 'room_id' => $dirty->id, 'title' => 'Clean', 'task_category_id' => $hotel->cleaning_task_category_id, 'assigned_to_team_id' => $hotel->housekeeping_team_id, 'status' => 'pending']);
    app(HousekeepingService::class)->taskCreated($task);

    $response = fdGet($this, $hotel->owner, '/api/housekeeping/board')->assertOk()
        ->assertJsonPath('body.counts.clean', 2)
        ->assertJsonPath('body.counts.dirty', 1)
        ->assertJsonPath('body.counts.out_of_order', 1)
        ->assertJsonCount(3, 'body.rooms');

    $rows = collect($response->json('body.rooms'))->keyBy('room.id');
    expect($rows[$occupied->id]['departure_date'])->toBe($stay->planned_departure_date->toDateString())
        ->and($rows[$dirty->id]['open_task']['id'])->toBe($task->id)
        ->and($rows[$dirty->id]['room']['ready'])->toBeFalse()
        ->and($rows[$broken->id]['room']['out_of_order']['reason'])->toBe('Leak');
});

it('filters the board by housekeeping status, room status, floor, building and team', function () {
    [$hotel, $type, [$a, $b]] = fdHotel(2);
    Room::withoutGlobalScope('hotel')->whereKey($a->id)->update(['floor' => '7', 'building' => 'Annex', 'housekeeping_status' => 'dirty']);
    $task = Task::create(['hotel_id' => $hotel->id, 'room_id' => $a->id, 'title' => 'Clean', 'task_category_id' => $hotel->cleaning_task_category_id, 'assigned_to_team_id' => $hotel->housekeeping_team_id, 'status' => 'pending']);
    app(HousekeepingService::class)->taskCreated($task);

    foreach (['housekeeping_status=dirty', 'floor=7', 'building=Annex', "team_id={$hotel->housekeeping_team_id}"] as $filter) {
        fdGet($this, $hotel->owner, "/api/housekeeping/board?{$filter}")->assertOk()
            ->assertJsonCount(1, 'body.rooms')->assertJsonPath('body.rooms.0.room.id', $a->id);
    }

    fdGet($this, $hotel->owner, '/api/housekeeping/board?status=out_of_order')->assertOk()->assertJsonCount(0, 'body.rooms');
});

it('needs rooms.view, and a super admin must name the hotel', function () {
    [$hotel] = fdHotel(1);

    fdGet($this, fdEmployee($hotel, [Permission::TASKS_VIEW]), '/api/housekeeping/board')->assertForbidden();
    fdGet($this, fdEmployee($hotel, [Permission::ROOMS_VIEW]), '/api/housekeeping/board')->assertOk();

    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    fdGet($this, $super, '/api/housekeeping/board')->assertForbidden();
    fdGet($this, $super, "/api/housekeeping/board?hotel_id={$hotel->id}")->assertOk();
});

it('shows the board of a 500-room hotel in under 2 seconds with a fixed number of queries', function () {
    [$hotel, $type] = fdHotel(1);
    $rows = collect(range(1, 499))->map(fn (int $n) => [
        'id' => (string) Str::uuid(), 'hotel_id' => $hotel->id, 'room_type_id' => $type->id,
        'room_number' => 'B'.$n, 'floor' => (string) intdiv($n, 50), 'status' => 'available',
        'housekeeping_status' => $n % 3 === 0 ? 'dirty' : 'clean', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('rooms')->insert($rows->all());

    fdGet($this, $hotel->owner, '/api/housekeeping/board')->assertOk();

    DB::enableQueryLog();
    $start = microtime(true);
    fdGet($this, $hotel->owner, '/api/housekeeping/board')->assertOk()->assertJsonCount(500, 'body.rooms');
    $elapsed = microtime(true) - $start;
    $queries = count(DB::getQueryLog());

    expect($elapsed)->toBeLessThan(2.0)
        ->and($queries)->toBeLessThanOrEqual(8);
});

it('lists maintenance tasks open first, most urgent first, oldest first, with room and reporter', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    $old = fdPost($this, $hotel->owner, "/api/task/{$cleaning->id}/issues", ['description' => 'Leak', 'room_unsellable' => true])->json('body.maintenance_task.id');
    $this->travel(1)->hours();
    $urgent = fdPost($this, $hotel->owner, "/api/task/{$cleaning->id}/issues", ['description' => 'Fire alarm', 'priority' => 'high'])->json('body.maintenance_task.id');
    $done = fdPost($this, $hotel->owner, "/api/task/{$cleaning->id}/issues", ['description' => 'Bulb'])->json('body.maintenance_task.id');
    hkSetTaskStatus($this, $hotel->owner, Task::find($done), 'completed')->assertOk();
    Task::create(['hotel_id' => $hotel->id, 'title' => 'Not maintenance', 'status' => 'pending']);

    $response = fdGet($this, $hotel->owner, '/api/maintenance/tasks')->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$urgent, $old, $done])
        ->and($response->json('body.data.1.reporter.id'))->toBe($hotel->owner->id)
        ->and($response->json('body.data.1.source_task_id'))->toBe($cleaning->id)
        ->and($response->json('body.data.1.room.out_of_order.reason'))->toBe('Leak');

    fdGet($this, $hotel->owner, '/api/maintenance/tasks?filter[room_out_of_order]=true')->assertOk()->assertJsonCount(3, 'body.data');
    fdGet($this, $hotel->owner, '/api/maintenance/tasks?filter[status]=completed')->assertOk()->assertJsonCount(1, 'body.data');
});

it('flags an out-of-order room past its expected end date as overdue', function () {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    $id = fdPost($this, $hotel->owner, "/api/task/{$cleaning->id}/issues", ['description' => 'Leak', 'room_unsellable' => true])->json('body.maintenance_task.id');
    hkPatch($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['expected_end_date' => now()->addDay()->toDateString()])->assertOk();

    $this->travel(2)->days();

    fdGet($this, $hotel->owner, '/api/maintenance/tasks')->assertOk()
        ->assertJsonPath('body.data.0.id', $id)
        ->assertJsonPath('body.data.0.room.out_of_order.overdue', true);
});

it('filters the board by the ground floor', function () {
    [$hotel, $type, [$ground, $upstairs]] = fdHotel(2);
    Room::withoutGlobalScope('hotel')->whereKey($ground->id)->update(['floor' => '0']);
    Room::withoutGlobalScope('hotel')->whereKey($upstairs->id)->update(['floor' => '1']);

    fdGet($this, $hotel->owner, '/api/housekeeping/board?floor=0')->assertOk()
        ->assertJsonCount(1, 'body.rooms')
        ->assertJsonPath('body.rooms.0.room.id', $ground->id);
});

it('lists no maintenance tasks when the hotel has no Maintenance team', function () {
    [$hotel] = fdHotel(1);
    Task::create(['hotel_id' => $hotel->id, 'title' => 'Teamless task', 'status' => 'pending']);
    $hotel->update(['maintenance_team_id' => null, 'maintenance_task_category_id' => null]);

    fdGet($this, $hotel->owner, '/api/maintenance/tasks')->assertOk()->assertJsonCount(0, 'body.data');
});
