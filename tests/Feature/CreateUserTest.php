<?php

// Admin & super admin can create, read, update and delete users

// only super admin can index all users in the system

// if a user is not an admin or super admin, they cannot access any of the user management routes

// each admin user can only manage users that belong to their own hotel

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function userManagementApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithOwnedHotelForUsers(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function staffForUsers(Hotel $hotel, array $overrides = []): User
{
    return User::factory()->role(UserRole::EMPLOYEE)->create(array_merge([
        'hotel_id' => $hotel->id,
    ], $overrides));
}

function teamForUsers(Hotel $hotel, array $overrides = []): Team
{
    return Team::create(array_merge([
        'hotel_id' => $hotel->id,
        'name' => 'Housekeeping',
    ], $overrides));
}

// access restrictions

it('rejects an unauthenticated request to any user management route', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->getJson('/api/users')->assertStatus(401);
    $this->withHeaders(userManagementApiHeaders())->postJson('/api/users', [])->assertStatus(401);
    $this->withHeaders(userManagementApiHeaders())->getJson("/api/users/{$staff->id}")->assertStatus(401);
    $this->withHeaders(userManagementApiHeaders())->putJson("/api/users/{$staff->id}", [])->assertStatus(401);
    $this->withHeaders(userManagementApiHeaders())->deleteJson("/api/users/{$staff->id}")->assertStatus(401);
});

it('rejects an employee from accessing any user management route', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $employee = staffForUsers($hotel);
    $target = staffForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/users')->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/users', ['name' => 'New Hire', 'email' => 'new@hire.com', 'password' => 'Password123!'])
        ->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/users/{$target->id}")->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/users/{$target->id}", ['name' => 'Hijacked'])->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($employee, 'sanctum')
        ->deleteJson("/api/users/{$target->id}")->assertStatus(403);
});

// index

it('only lists users belonging to the admin own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $mine = staffForUsers($hotel);

    [, $otherHotel] = adminWithOwnedHotelForUsers();
    staffForUsers($otherHotel);

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/users');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(2);
    expect($data->pluck('id'))->toContain($admin->id, $mine->id);
});

it('lets an admin see only themself when their own hotel_id column is not set', function () {
    // hotel_id is the single source of truth for a user's hotel — owning a
    // hotel via Hotel.owner_id does not associate the admin with its staff
    // unless hotel_id is also backfilled (as /api/register does).
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $notMine = staffForUsers($hotel);

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/users');

    $response->assertOk();
    $ids = collect($response->json('body.data'))->pluck('id');
    expect($ids)->toContain($admin->id);
    expect($ids)->not->toContain($notMine->id);
});

it('lets a super admin index every user in the system', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    staffForUsers($hotel);
    [, $otherHotel] = adminWithOwnedHotelForUsers();
    staffForUsers($otherHotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/users');

    $response->assertOk();
    // 2 admins + 2 staff + the super admin
    expect(collect($response->json('body.data')))->toHaveCount(5);
});

// store

it('creates a user scoped to the caller own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    [, $otherHotel] = adminWithOwnedHotelForUsers();

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'hotel_id' => $otherHotel->id,
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);

    expect(User::where('email', 'new-hire@example.com')->where('hotel_id', $otherHotel->id)->exists())->toBeFalse();
});

it('rejects an admin without an associated hotel from creating a user', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'User does not have an associated hotel.');
});

it('creates a user when team_id belongs to the caller own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $team = teamForUsers($hotel);

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
            'team_id' => $team->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.team_id', $team->id);
});

it('rejects creating a user whose team_id belongs to a different hotel', function () {
    [$admin] = adminWithOwnedHotelForUsers();
    [, $otherHotel] = adminWithOwnedHotelForUsers();
    $team = teamForUsers($otherHotel);

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
            'team_id' => $team->id,
        ]);

    $response->assertStatus(403);
    expect(User::where('email', 'new-hire@example.com')->exists())->toBeFalse();
});

it('lets a super admin create a user for any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/users', [
            'hotel_id' => $hotel->id,
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);
});

// show

it('lets an admin view a user belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/users/{$staff->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $staff->id);
});

it('rejects an admin viewing a user belonging to a different hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [$stranger] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($stranger, 'sanctum')
        ->getJson("/api/users/{$staff->id}")
        ->assertStatus(403);
});

it('lets a super admin view a user belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/users/{$staff->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $staff->id);
});

// update

it('lets an admin update a user belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['name' => 'Updated Name'])
        ->assertOk()
        ->assertJsonPath('body.name', 'Updated Name');
});

it('rejects an admin updating a user belonging to a different hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [$stranger] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($stranger, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect($staff->fresh()->name)->not->toBe('Hijacked');
});

it('ignores an attempt to reassign a user to a different hotel on update', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [, $otherHotel] = adminWithOwnedHotelForUsers();

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['hotel_id' => $otherHotel->id]);

    $response->assertOk();
    expect($staff->fresh()->hotel_id)->toBe($hotel->id);
});

it('allows updating a user team_id to one belonging to the same hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $team = teamForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['team_id' => $team->id])
        ->assertOk()
        ->assertJsonPath('body.team_id', $team->id);
});

it('rejects updating a user team_id to one belonging to a different hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [, $otherHotel] = adminWithOwnedHotelForUsers();
    $team = teamForUsers($otherHotel);

    $response = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['team_id' => $team->id]);

    $response->assertStatus(403);
    expect($staff->fresh()->team_id)->not->toBe($team->id);
});

it('lets a super admin update a user belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['name' => 'Updated by Super Admin'])
        ->assertOk()
        ->assertJsonPath('body.name', 'Updated by Super Admin');
});

// privilege and account boundaries

it('rejects an admin creating a super admin', function () {
    [$admin] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Escalated',
            'email' => 'escalated@example.com',
            'password' => 'Password123!',
            'role' => UserRole::SUPER_ADMIN->value,
        ])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Only a super admin can assign the super admin role.');

    expect(User::where('email', 'escalated@example.com')->exists())->toBeFalse();
});

it('rejects an admin promoting themselves to super admin', function () {
    [$admin] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$admin->id}", ['role' => UserRole::SUPER_ADMIN->value])
        ->assertStatus(403);

    expect($admin->fresh()->isSuperAdmin())->toBeFalse();
});

it('still lets an admin create another admin for their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'Co-Manager',
            'email' => 'co-manager@example.com',
            'password' => 'Password123!',
            'role' => UserRole::ADMIN->value,
        ])
        ->assertStatus(201)
        ->assertJsonPath('body.role', UserRole::ADMIN->value)
        ->assertJsonPath('body.hotel_id', $hotel->id);
});

it('ignores account membership an admin sends on update, so they cannot join another account', function () {
    [$admin] = adminWithOwnedHotelForUsers();
    [, $otherHotel] = adminWithOwnedHotelForUsers();
    $otherGuest = Guest::create([
        'hotel_id' => $otherHotel->id,
        'external_id' => 'other-account-guest',
        'channel' => 'booking_com',
    ]);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$admin->id}", [
            'hotel_group_id' => $otherHotel->hotel_group_id,
            'group_role' => 'owner',
        ])
        ->assertOk();

    $admin->refresh();
    expect($admin->hotel_group_id)->toBeNull()
        ->and($admin->group_role)->toBeNull();

    $guests = $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/guest')
        ->assertOk();

    expect(collect($guests->json('body.data'))->pluck('id'))->not->toContain($otherGuest->id);
});

it('ignores account membership an admin sends on create', function () {
    [$admin] = adminWithOwnedHotelForUsers();
    [, $otherHotel] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
            'hotel_group_id' => $otherHotel->hotel_group_id,
            'group_role' => 'owner',
        ])
        ->assertStatus(201);

    $created = User::where('email', 'new-hire@example.com')->first();
    expect($created->hotel_group_id)->toBeNull()
        ->and($created->group_role)->toBeNull();
});

it('rejects an admin updating a super admin attached to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$superAdmin->id}", ['email' => 'taken-over@example.com'])
        ->assertStatus(403);

    expect($superAdmin->fresh()->email)->not->toBe('taken-over@example.com');
});

it('rejects an admin with no hotel managing another hotel-less user', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotelLessUser = User::factory()->role(UserRole::ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/users/{$hotelLessUser->id}")
        ->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$hotelLessUser->id}", ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect($hotelLessUser->fresh()->name)->not->toBe('Hijacked');
});

it('lets a super admin assign the super admin role', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['role' => UserRole::SUPER_ADMIN->value])
        ->assertOk();

    expect($staff->fresh()->isSuperAdmin())->toBeTrue();
});

// destroy

it('lets an admin delete a user belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/users/{$staff->id}")
        ->assertOk();

    expect(User::find($staff->id))->toBeNull();
});

it('rejects an admin deleting a user belonging to a different hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [$stranger] = adminWithOwnedHotelForUsers();

    $this->withHeaders(userManagementApiHeaders())->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/users/{$staff->id}")
        ->assertStatus(403);

    expect(User::find($staff->id))->not->toBeNull();
});

// staff roles

function staffRoleForUsers(Hotel $hotel, array $overrides = []): StaffRole
{
    return StaffRole::create(array_merge([
        'hotel_id' => $hotel->id,
        'name' => 'Front Desk',
        'permissions' => [Permission::ROOMS_VIEW->value],
    ], $overrides));
}

it('lets an admin assign a staff role from their own hotel and records it', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $role = staffRoleForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['staff_role_id' => $role->id])
        ->assertOk()
        ->assertJsonPath('body.staff_role_id', $role->id)
        ->assertJsonPath('body.staff_role.name', 'Front Desk')
        ->assertJsonPath('body.permissions', [Permission::ROOMS_VIEW->value]);

    $event = EventLog::withoutGlobalScopes()
        ->where('event_type', 'user.staff_role_assigned')
        ->where('subject_id', $staff->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->changes['staff_role_id'])->toBe(['from' => null, 'to' => $role->id]);
});

it('creates an employee with a staff role', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $role = staffRoleForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
            'staff_role_id' => $role->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('body.staff_role_id', $role->id);
});

it('rejects assigning a staff role belonging to a different hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    [, $otherHotel] = adminWithOwnedHotelForUsers();
    $role = staffRoleForUsers($otherHotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['staff_role_id' => $role->id])
        ->assertStatus(403);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/users', [
            'name' => 'New Hire',
            'email' => 'new-hire@example.com',
            'password' => 'Password123!',
            'staff_role_id' => $role->id,
        ])
        ->assertStatus(403);

    expect($staff->fresh()->staff_role_id)->toBeNull()
        ->and(User::where('email', 'new-hire@example.com')->exists())->toBeFalse();
});

it('rejects giving a staff role to an admin', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $coManager = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => $hotel->id]);
    $role = staffRoleForUsers($hotel);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$coManager->id}", ['staff_role_id' => $role->id])
        ->assertStatus(422);

    expect($coManager->fresh()->staff_role_id)->toBeNull();
});

it('clears the staff role of an employee promoted to admin', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $role = staffRoleForUsers($hotel);
    $staff = staffForUsers($hotel, ['staff_role_id' => $role->id]);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['role' => UserRole::ADMIN->value])
        ->assertOk()
        ->assertJsonPath('body.staff_role_id', null);
});

it('moves an employee back to the default permissions when their role is removed', function () {
    [$admin, $hotel] = adminWithOwnedHotelForUsers();
    $role = staffRoleForUsers($hotel);
    $staff = staffForUsers($hotel, ['staff_role_id' => $role->id]);

    $this->withHeaders(userManagementApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/users/{$staff->id}", ['staff_role_id' => null])
        ->assertOk()
        ->assertJsonPath('body.staff_role_id', null)
        ->assertJsonPath('body.permissions', array_map(
            fn (Permission $permission) => $permission->value,
            Permission::employeeDefaults(),
        ));
});

it('lets a super admin delete a user belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/users/{$staff->id}")
        ->assertOk();

    expect(User::find($staff->id))->toBeNull();
});
