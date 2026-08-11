<?php

// Admin & super admin can create, read, update and delete users

// only super admin can index all users in the system

// if a user is not an admin or super admin, they cannot access any of the user management routes

// each admin user can only manage users that belong to their own hotel

use App\Enums\UserRole;
use App\Models\Hotel;
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

it('lets a super admin delete a user belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotelForUsers();
    $staff = staffForUsers($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(userManagementApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/users/{$staff->id}")
        ->assertOk();

    expect(User::find($staff->id))->toBeNull();
});
