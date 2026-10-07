<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\Task;
use App\Models\User;
use App\Services\BookingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function permissionApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function hotelForPermissions(): Hotel
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

/**
 * An employee of the hotel. With no permissions given they have no staff role
 * and so get the defaults; otherwise they hold a role granting exactly those.
 *
 * @param  list<Permission>|null  $permissions
 */
function employeeWithPermissions(Hotel $hotel, ?array $permissions = null): User
{
    $role = $permissions === null ? null : StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create([
        'hotel_id' => $hotel->id,
        'staff_role_id' => $role?->id,
    ]);
}

function guestForPermissions(Hotel $hotel): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);
}

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
    'availability' => [availabilityProbeUrl(), Permission::AVAILABILITY_VIEW],
    'ai insights' => ['/api/ai-insights', Permission::AI_INSIGHTS_VIEW],
    'bookings' => ['/api/booking', Permission::BOOKINGS_VIEW],
    'booking cancellation requests' => ['/api/booking/cancellation-requests', Permission::BOOKINGS_VIEW],
    'dashboard' => ['/api/dashboard', Permission::DASHBOARD_VIEW],
    'guests' => ['/api/guest', Permission::GUESTS_VIEW],
    'hotel policies' => ['/api/hotel-policy', Permission::HOTEL_POLICIES_VIEW],
    'knowledge base articles' => ['/api/knowledge-base-articles', Permission::KNOWLEDGE_BASE_ARTICLES_VIEW],
    'recommendations' => ['/api/recommendation', Permission::RECOMMENDATIONS_VIEW],
    'reservations' => ['/api/reservation', Permission::RESERVATIONS_VIEW],
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
]);

it('never gives employees without a role the housekeeping or out-of-order permissions', function () {
    expect(Permission::employeeDefaults())
        ->not->toContain(Permission::ROOMS_UPDATE_HOUSEKEEPING_STATUS)
        ->not->toContain(Permission::ROOMS_SET_OUT_OF_ORDER);
});

it('lets an employee run housekeeping and maintenance actions only when their role grants it', function (string $action, Permission $permission) {
    [$hotel, $room, $cleaning] = hkVacatedRoom($this);
    hkPut($this, $hotel->owner, "/api/hotel/{$hotel->id}", ['inspection_required' => true])->assertOk();
    hkSetTaskStatus($this, $hotel->owner, $cleaning, 'completed')->assertOk();
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
        fdPost($this, $hotel->owner, "/api/room/{$room->id}/out-of-order", ['reason' => 'Leak'])->assertOk();
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
        fdPost($this, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
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

it('replaces the defaults with the role instead of adding to them', function () {
    $hotel = hotelForPermissions();
    $housekeeper = employeeWithPermissions($hotel, [Permission::ROOMS_VIEW]);

    $this->withHeaders(permissionApiHeaders())->actingAs($housekeeper, 'sanctum')
        ->getJson('/api/room')->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($housekeeper, 'sanctum')
        ->getJson('/api/guest')->assertForbidden();
});

it('lets an employee change and delete guests only when their role grants it', function () {
    $hotel = hotelForPermissions();
    $manager = employeeWithPermissions($hotel, [Permission::GUESTS_UPDATE, Permission::GUESTS_DELETE]);
    $reader = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW]);
    $guest = guestForPermissions($hotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($reader, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['first_name' => 'Renamed'])->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($reader, 'sanctum')
        ->deleteJson("/api/guest/{$guest->id}")->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($manager, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['first_name' => 'Renamed'])->assertOk();

    $this->withHeaders(permissionApiHeaders())->actingAs($manager, 'sanctum')
        ->deleteJson("/api/guest/{$guest->id}")->assertOk();

    expect(Guest::withTrashed()->find($guest->id))
        ->first_name->toBe('Renamed')
        ->trashed()->toBeTrue();
});

it('does not let a granted permission reach another hotel', function () {
    $hotel = hotelForPermissions();
    $otherHotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW, Permission::GUESTS_UPDATE, Permission::GUESTS_DELETE]);
    $otherGuest = guestForPermissions($otherHotel);

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/guest/{$otherGuest->id}")->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/guest/{$otherGuest->id}", ['first_name' => 'Hijacked'])->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->deleteJson("/api/guest/{$otherGuest->id}")->assertForbidden();

    $listed = $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/guest')->assertOk();

    expect(collect($listed->json('body.data'))->pluck('id'))->not->toContain($otherGuest->id)
        ->and($otherGuest->fresh())->first_name->not->toBe('Hijacked')
        ->trashed()->toBeFalse();
});

it('keeps admin-only areas closed to an employee whose role grants everything', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, Permission::cases());

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/users')->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/users/{$employee->id}", ['role' => UserRole::ADMIN->value])->assertForbidden();

    $this->withHeaders(permissionApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Hello'])->assertForbidden();

    expect($employee->fresh()->isEmployee())->toBeTrue();
});

it('grants nothing when the assigned role can no longer be read', function () {
    $hotel = hotelForPermissions();
    $employee = employeeWithPermissions($hotel, [Permission::GUESTS_VIEW]);
    $employee->staffRole->delete();

    // Guests are in the defaults too, so a 403 proves there is no fallback.
    $this->withHeaders(permissionApiHeaders())->actingAs($employee->fresh(), 'sanctum')
        ->getJson('/api/guest')->assertForbidden();
});

it('gives employees without a role booking edits but never the capacity override', function () {
    expect(Permission::employeeDefaults())
        ->toContain(Permission::BOOKINGS_UPDATE)
        ->not->toContain(Permission::BOOKINGS_OVERRIDE_CAPACITY);
});

it('lets an employee run booking and activity actions only when their role grants it', function (string $action, Permission $permission) {
    test()->travelTo('2026-10-05 09:00:00');
    $hotel = avHotel();
    $activity = abActivity($hotel, ['daily_capacity' => 2]);
    $booking = abBook($hotel, $activity, '2026-10-09', 1);
    app(BookingCancellationService::class)
        ->request($booking, Guest::withoutGlobalScope('hotel')->find($booking->guest_id), 'Flight changed');

    $call = fn ($user) => match ($action) {
        'edit booking' => abPatch($this, $user, "/api/booking/{$booking->id}", ['notes' => 'Window seat']),
        'activity availability' => fdGet($this, $user, "/api/activity/{$activity->id}/availability?from=2026-10-09"),
        'approve cancellation' => fdPost($this, $user, "/api/booking/{$booking->id}/cancellation-request/approve"),
        'decline cancellation' => fdPost($this, $user, "/api/booking/{$booking->id}/cancellation-request/decline", ['note' => 'Non-refundable']),
        'override capacity' => fdPost($this, $user, '/api/booking', [
            'guest_id' => abGuest($hotel)->id,
            'activity_id' => $activity->id,
            'scheduled_date' => '2026-10-09',
            'pax' => 2,
            'charge_model' => 'pay_on_site',
            'capacity_override' => true,
        ]),
    };

    // The override is checked on top of taking the booking.
    $base = $action === 'override capacity' ? [Permission::BOOKINGS_CREATE] : [];

    $call(fdEmployee($hotel, $base))->assertForbidden();
    expect($call(fdEmployee($hotel, [...$base, $permission]))->status())->toBeIn([200, 201]);
})->with([
    'edit booking' => ['edit booking', Permission::BOOKINGS_UPDATE],
    'activity availability' => ['activity availability', Permission::ACTIVITIES_VIEW],
    'approve cancellation' => ['approve cancellation', Permission::BOOKINGS_UPDATE_STATUS],
    'decline cancellation' => ['decline cancellation', Permission::BOOKINGS_UPDATE_STATUS],
    'override capacity' => ['override capacity', Permission::BOOKINGS_OVERRIDE_CAPACITY],
]);
