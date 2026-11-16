<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    // The ledger is off by default (spec 019); these tests exercise it.
    config(['features.transactions' => true]);
});

/**
 * A valid availability lookup a month out, so a run that crosses midnight
 * cannot turn its arrival date into "the past".
 */
function availabilityProbeUrl(): string
{
    return '/api/availability?'.http_build_query([
        'arrival_date' => now()->addDays(30)->toDateString(),
        'departure_date' => now()->addDays(31)->toDateString(),
    ]);
}

it('lets employees without a role read availability but never overbook by default', function () {
    expect(Permission::employeeDefaults())
        ->toContain(Permission::AVAILABILITY_VIEW)
        ->not->toContain(Permission::RESERVATIONS_OVERBOOK);
});

it('gives an employee without a staff role exactly the default permissions', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('body.staff_role', null)
        ->assertJsonPath('body.permissions', array_map(
            fn (Permission $permission) => $permission->value,
            Permission::employeeDefaults(),
        ));

    foreach (['/api/guest', '/api/activity', '/api/booking', '/api/dashboard', availabilityProbeUrl()] as $allowed) {
        $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
            ->getJson($allowed)->assertOk();
    }

    foreach (['/api/room', '/api/task', '/api/reservation', '/api/transaction', '/api/stays'] as $denied) {
        $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
            ->getJson($denied)->assertForbidden();
    }
});

it('does not give an employee with no role the hotel list', function () {
    $hotel = hotelForPermissions();

    $this->withHeaders(permissionApiHeaders())->actingAs(employeeWithPermissions($hotel), 'sanctum')
        ->getJson('/api/hotel')->assertForbidden();

    expect(Permission::employeeDefaults())->not->toContain(Permission::HOTELS_VIEW);
});

it('reports every permission for an admin', function () {
    $hotel = hotelForPermissions();
    $admin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(permissionApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonCount(count(Permission::cases()), 'body.permissions');
});

it('lets an employee read a resource only when their role grants it', function (string $endpoint, Permission $permission) {
    $hotel = hotelForPermissions();
    $granted = employeeWithPermissions($hotel, [$permission]);
    $withoutIt = employeeWithPermissions($hotel, []);

    $this->withHeaders(permissionApiHeaders())->actingAs($granted, 'sanctum')
        ->getJson($endpoint)->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($withoutIt, 'sanctum')
        ->getJson($endpoint)->assertForbidden();
})->with([
    'activities' => ['/api/activity', Permission::ACTIVITIES_VIEW],
    'activity categories' => ['/api/activity-category', Permission::ACTIVITY_CATEGORIES_VIEW],
    'ai activity' => ['/api/ai-activity', Permission::HISTORY_VIEW],
    'availability' => [availabilityProbeUrl(), Permission::AVAILABILITY_VIEW],
    'ai insights' => ['/api/ai-insights', Permission::AI_INSIGHTS_VIEW],
    'bookings' => ['/api/booking', Permission::BOOKINGS_VIEW],
    'conversations' => ['/api/conversations', Permission::CONVERSATIONS_VIEW],
    'booking cancellation requests' => ['/api/booking/cancellation-requests', Permission::BOOKINGS_VIEW],
    'dashboard' => ['/api/dashboard', Permission::DASHBOARD_VIEW],
    'hotels' => ['/api/hotel', Permission::HOTELS_VIEW],
    'guests' => ['/api/guest', Permission::GUESTS_VIEW],
    'hotel policies' => ['/api/hotel-policy', Permission::HOTEL_POLICIES_VIEW],
    'knowledge base articles' => ['/api/knowledge-base-articles', Permission::KNOWLEDGE_BASE_ARTICLES_VIEW],
    'recommendations' => ['/api/recommendation', Permission::RECOMMENDATIONS_VIEW],
    'reservations' => ['/api/reservation', Permission::RESERVATIONS_VIEW],
    'reservation imports' => ['/api/reservation/imports', Permission::RESERVATIONS_IMPORT],
    'unassigned arrivals' => ['/api/reservation/unassigned-arrivals', Permission::RESERVATIONS_ASSIGN_ROOM],
    'rooms' => ['/api/room', Permission::ROOMS_VIEW],
    'room types' => ['/api/room-types', Permission::ROOM_TYPES_VIEW],
    'stays' => ['/api/stays', Permission::STAYS_VIEW],
    'arrivals' => ['/api/stays/arrivals', Permission::STAYS_VIEW],
    'departures' => ['/api/stays/departures', Permission::STAYS_VIEW],
    'in-house' => ['/api/stays/in-house', Permission::STAYS_VIEW],
    'task categories' => ['/api/task-category', Permission::TASK_CATEGORIES_VIEW],
    'tasks' => ['/api/task', Permission::TASKS_VIEW],
    'teams' => ['/api/team', Permission::TEAMS_VIEW],
    'transactions' => ['/api/transaction', Permission::TRANSACTIONS_VIEW],
    'housekeeping board' => ['/api/housekeeping/board', Permission::ROOMS_VIEW],
    'maintenance list' => ['/api/maintenance/tasks', Permission::TASKS_VIEW],
    'voucher templates' => ['/api/voucher-templates', Permission::VOUCHER_TEMPLATES_VIEW],
    'voucher fields' => ['/api/voucher-templates/fields', Permission::VOUCHER_TEMPLATES_VIEW],
    'reservation export' => ['/api/reservation/export', Permission::RESERVATIONS_EXPORT],
    'activity export' => ['/api/activity/export', Permission::ACTIVITIES_EXPORT],
    'booking export' => ['/api/booking/export', Permission::BOOKINGS_EXPORT],
    'guest export' => ['/api/guest/export', Permission::GUESTS_EXPORT],
]);

it('does not let viewing a list export it, and gives no export by default (spec 021)', function () {
    $exports = [
        '/api/reservation/export' => Permission::RESERVATIONS_VIEW,
        '/api/activity/export' => Permission::ACTIVITIES_VIEW,
        '/api/booking/export' => Permission::BOOKINGS_VIEW,
        '/api/guest/export' => Permission::GUESTS_VIEW,
    ];

    foreach ([Permission::RESERVATIONS_EXPORT, Permission::ACTIVITIES_EXPORT, Permission::BOOKINGS_EXPORT, Permission::GUESTS_EXPORT] as $permission) {
        expect(Permission::employeeDefaults())->not->toContain($permission);
    }

    $hotel = hotelForPermissions();
    $viewer = employeeWithPermissions($hotel, array_values($exports));
    $noRole = employeeWithPermissions($hotel);

    foreach (array_keys($exports) as $endpoint) {
        $this->withHeaders(permissionApiHeaders())->actingAs($viewer, 'sanctum')
            ->getJson($endpoint)->assertForbidden();

        $this->withHeaders(permissionApiHeaders())->actingAs($noRole, 'sanctum')
            ->getJson($endpoint)->assertForbidden();
    }

    $listed = collect($this->withHeaders(permissionApiHeaders())->actingAs(adminOfHotel($hotel), 'sanctum')
        ->getJson('/api/permissions')->assertOk()->json('body'))->flatten()->all();

    expect($listed)->toContain('reservations.export', 'activities.export', 'bookings.export', 'guests.export');
});

it('never gives employees without a role the housekeeping or out-of-order permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS)
        ->not->toContain(Permission::ROOMS_SET_OUT_OF_ORDER);
});

it('lets an employee run housekeeping and maintenance actions only when their role grants it', function (string $action, Permission $permission) {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    hkPut($this, adminOfHotel($hotel), "/api/hotel/{$hotel->id}", ['inspection_required' => true])->assertOk();
    hkSetTaskStatus($this, adminOfHotel($hotel), $cleaning, 'completed')->assertOk();
    $inspection = Task::where('housekeeping_kind', 'inspection')->sole();

    $call = fn ($user) => match ($action) {
        'housekeeping status' => hkPut($this, $user, "/api/room/{$room->id}/housekeeping-status", ['housekeeping_status' => 'dirty', 'reason' => 'Spill']),
        'take out of order' => fdPost($this, $user, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak']),
        'edit out of order' => hkPatch($this, $user, "/api/room/{$room->id}/out-of-order", ['reason' => 'Bigger leak']),
        'return to service' => fdPost($this, $user, "/api/room/{$room->id}/return-to-service"),
        'inspection' => fdPost($this, $user, "/api/task/{$inspection->id}/inspection", ['result' => 'pass']),
        'report issue' => fdPost($this, $user, "/api/task/{$cleaning->id}/issues", ['description' => 'Leak']),
    };

    if (in_array($action, ['edit out of order', 'return to service'], true)) {
        fdPost($this, adminOfHotel($hotel), "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
    }

    $call(fdEmployee($hotel, Permission::employeeDefaults()))->assertForbidden();
    expect($call(fdEmployee($hotel, [$permission]))->status())->toBeIn([200, 201]);
})->with([
    'housekeeping status' => ['housekeeping status', Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS],
    'take out of order' => ['take out of order', Permission::ROOMS_SET_OUT_OF_ORDER],
    'edit out of order' => ['edit out of order', Permission::ROOMS_SET_OUT_OF_ORDER],
    'return to service' => ['return to service', Permission::ROOMS_SET_OUT_OF_ORDER],
    'inspection' => ['inspection', Permission::TASKS_UPDATE],
    'report issue' => ['report issue', Permission::TASKS_UPDATE],
]);

it('never gives employees without a role the stays permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::STAYS_VIEW)
        ->not->toContain(Permission::STAYS_CHECK_IN)
        ->not->toContain(Permission::STAYS_CHECK_OUT);
});

it('lets an employee check guests in and out only when their role grants it', function (string $action, string $scope) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);

    if ($action === 'check-out') {
        fdPost($this, adminOfHotel($hotel), "/api/stays/{$stay->id}/check-in")->assertOk();
    }

    $permission = $action === 'check-in' ? Permission::STAYS_CHECK_IN : Permission::STAYS_CHECK_OUT;
    $uri = $scope === 'room' ? "/api/stays/{$stay->id}/{$action}" : "/api/reservation/{$reservation->id}/{$action}";

    fdPost($this, fdEmployee($hotel, Permission::employeeDefaults()), $uri)->assertForbidden();
    fdPost($this, fdEmployee($hotel, [$permission]), $uri)->assertOk();
})->with([
    'check-in a room' => ['check-in', 'room'],
    'check-in a reservation' => ['check-in', 'reservation'],
    'check-out a room' => ['check-out', 'room'],
    'check-out a reservation' => ['check-out', 'reservation'],
]);

it('never gives employees without a role the room assignment or room move permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::RESERVATIONS_ASSIGN_ROOM)
        ->not->toContain(Permission::RESERVATIONS_MOVE_ROOM);
});

it('lets an employee assign rooms and move guests only when their role grants it', function (string $action) {
    [$hotel, $type, $rooms] = fdHotel(3);
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    [$stay] = fdStays($reservation);
    $line = $reservation->reservationRooms()->sole();

    if ($action === 'move') {
        fdPost($this, adminOfHotel($hotel), "/api/stays/{$stay->id}/check-in")->assertOk();
    }

    $call = fn ($user) => match ($action) {
        'assign' => $this->withHeaders(permissionApiHeaders())->actingAs($user, 'sanctum')
            ->putJson("/api/reservation/{$reservation->id}/rooms", ['assignments' => [['line_id' => $line->id, 'room_id' => $rooms[1]->id]]]),
        'move' => fdPost($this, $user, "/api/stays/{$stay->id}/move-room", ['room_id' => $rooms[1]->id, 'reason' => 'Noise']),
    };
    $permission = $action === 'assign' ? Permission::RESERVATIONS_ASSIGN_ROOM : Permission::RESERVATIONS_MOVE_ROOM;

    $call(fdEmployee($hotel, Permission::employeeDefaults()))->assertForbidden();
    $call(fdEmployee($hotel, [$permission]))->assertOk();
})->with(['assign', 'move']);

it('never gives employees without a role the no-show permission', function () {
    expect(Permission::employeeDefaults())->not->toContain(Permission::RESERVATIONS_NO_SHOW);
});

it('lets an employee mark and undo no-shows only when their role grants it', function (string $action, string $scope) {
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id]);
    [$stay] = fdStays($reservation);
    $base = $scope === 'room' ? "/api/stays/{$stay->id}" : "/api/reservation/{$reservation->id}";

    if ($action === 'undo-no-show') {
        fdPost($this, adminOfHotel($hotel), "{$base}/no-show")->assertOk();
    }

    fdPost($this, fdEmployee($hotel, [Permission::RESERVATIONS_UPDATE, Permission::STAYS_CHECK_IN]), "{$base}/{$action}")->assertForbidden();
    fdPost($this, fdEmployee($hotel, [Permission::RESERVATIONS_NO_SHOW]), "{$base}/{$action}")->assertOk();
})->with([
    'mark a reservation' => ['no-show', 'reservation'],
    'mark a room' => ['no-show', 'room'],
    'undo a reservation' => ['undo-no-show', 'reservation'],
    'undo a room' => ['undo-no-show', 'room'],
]);
