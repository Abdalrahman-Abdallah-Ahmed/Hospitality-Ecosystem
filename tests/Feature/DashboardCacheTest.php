<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Spec 017 (FR-023, SC-011, research R10): the dashboard is reused for 60
| seconds per hotel and day, read only after the permission check, and never
| shared between hotels.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function dashboardFor(object $test, User $user)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->getJson('/api/dashboard');
}

function pendingTaskFor(Hotel $hotel): Task
{
    return Task::create([
        'hotel_id' => $hotel->id,
        'title' => 'Fix '.uniqid(),
        'created_by' => 'manual',
        'status' => 'pending',
        'priority' => 'normal',
    ]);
}

it('recalculates the figures at most once within 60 seconds', function () {
    $hotel = makeHotel();
    $admin = User::findOrFail($hotel->owner_id);

    $first = dashboardFor($this, $admin)->assertOk()->json('body');

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    pendingTaskFor($hotel);
    $queries = 0;

    $second = dashboardFor($this, $admin)->assertOk()->json('body');
    $queriesWhenCached = $queries;

    expect($second)->toBe($first)
        ->and($second['pending_tasks'])->toBe($first['pending_tasks']);

    $this->travel(61)->seconds();

    expect(dashboardFor($this, $admin)->assertOk()->json('body.pending_tasks'))->toBe($first['pending_tasks'] + 1)
        // a cached answer only authenticates the user; it runs no summary query
        ->and($queriesWhenCached)->toBeLessThan(10);
});

it('never shows one hotel another hotel\'s figures', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    pendingTaskFor($hotelA);
    pendingTaskFor($hotelA);

    $a = dashboardFor($this, User::findOrFail($hotelA->owner_id))->assertOk()->json('body.pending_tasks');
    $b = dashboardFor($this, User::findOrFail($hotelB->owner_id))->assertOk()->json('body.pending_tasks');

    expect($a)->toBe(2)->and($b)->toBe(0);
});

it('still refuses an employee without dashboard.view when a cached copy exists', function () {
    $hotel = makeHotel();
    dashboardFor($this, User::findOrFail($hotel->owner_id))->assertOk();

    $employee = fdEmployee($hotel, [Permission::TASKS_VIEW]);

    dashboardFor($this, $employee)->assertForbidden();
});

it('keys the cache on what the caller can see, not on their home hotel', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    pendingTaskFor($hotelA);
    pendingTaskFor($hotelB);
    pendingTaskFor($hotelB);

    // A super admin with a home hotel sees every hotel's figures...
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create(['hotel_id' => $hotelA->id]);
    expect(dashboardFor($this, $superAdmin)->assertOk()->json('body.pending_tasks'))->toBe(3);

    // ...which must never be served to that hotel's own admin.
    expect(dashboardFor($this, User::findOrFail($hotelA->owner_id))->assertOk()->json('body.pending_tasks'))->toBe(1);
});
