<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
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

    foreach (['/api/room', '/api/task', '/api/reservation', '/api/transaction'] as $denied) {
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
    'dashboard' => ['/api/dashboard', Permission::DASHBOARD_VIEW],
    'guests' => ['/api/guest', Permission::GUESTS_VIEW],
    'hotel policies' => ['/api/hotel-policy', Permission::HOTEL_POLICIES_VIEW],
    'knowledge base articles' => ['/api/knowledge-base-articles', Permission::KNOWLEDGE_BASE_ARTICLES_VIEW],
    'recommendations' => ['/api/recommendation', Permission::RECOMMENDATIONS_VIEW],
    'reservations' => ['/api/reservation', Permission::RESERVATIONS_VIEW],
    'rooms' => ['/api/room', Permission::ROOMS_VIEW],
    'room types' => ['/api/room-types', Permission::ROOM_TYPES_VIEW],
    'task categories' => ['/api/task-category', Permission::TASK_CATEGORIES_VIEW],
    'tasks' => ['/api/task', Permission::TASKS_VIEW],
    'teams' => ['/api/team', Permission::TEAMS_VIEW],
    'transactions' => ['/api/transaction', Permission::TRANSACTIONS_VIEW],
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
