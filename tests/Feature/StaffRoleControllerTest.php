<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function staffRoleApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithStaffRoleHotel(): array
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

function staffRoleFor(Hotel $hotel, array $overrides = []): StaffRole
{
    return StaffRole::create(array_merge([
        'hotel_id' => $hotel->id,
        'name' => 'Front Desk',
        'permissions' => [Permission::GUESTS_VIEW->value],
    ], $overrides));
}

// store

it('creates a staff role for the admin own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    [, $otherHotel] = adminWithStaffRoleHotel();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', [
            'hotel_id' => $otherHotel->id,
            'name' => 'Housekeeping',
            'description' => 'Rooms and tasks',
            'permissions' => [Permission::ROOMS_VIEW->value, Permission::TASKS_UPDATE->value],
        ])
        ->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id)
        ->assertJsonPath('body.name', 'Housekeeping')
        ->assertJsonPath('body.permissions', [Permission::ROOMS_VIEW->value, Permission::TASKS_UPDATE->value])
        ->assertJsonPath('body.users_count', 0);
});

it('creates a staff role with no permissions', function () {
    [$admin] = adminWithStaffRoleHotel();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'No Access', 'permissions' => []])
        ->assertStatus(201)
        ->assertJsonPath('body.permissions', []);
});

it('rejects an unknown or repeated permission', function (array $permissions) {
    [$admin] = adminWithStaffRoleHotel();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Front Desk', 'permissions' => $permissions])
        ->assertStatus(422);

    expect(StaffRole::count())->toBe(0);
})->with([
    'unknown permission' => [['guests.view', 'users.update']],
    'repeated permission' => [['guests.view', 'guests.view']],
    'not a list' => [['guests' => 'guests.view']],
]);

it('rejects a role name already used in the same hotel but allows it in another', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    [$otherAdmin] = adminWithStaffRoleHotel();
    staffRoleFor($hotel);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Front Desk', 'permissions' => []])
        ->assertStatus(422);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($otherAdmin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Front Desk', 'permissions' => []])
        ->assertStatus(201);
});

it('lets a deleted role name be reused', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    staffRoleFor($hotel)->delete();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Front Desk', 'permissions' => []])
        ->assertStatus(201);
});

it('records the creation of a staff role in the event log', function () {
    [$admin] = adminWithStaffRoleHotel();

    $id = $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Front Desk', 'permissions' => [Permission::GUESTS_VIEW->value]])
        ->assertStatus(201)
        ->json('body.id');

    expect(EventLog::withoutGlobalScopes()
        ->where('event_type', 'staff_role.created')
        ->where('subject_id', $id)
        ->exists())->toBeTrue();
});

// index and show

it('only lists staff roles of the admin own hotel', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    [, $otherHotel] = adminWithStaffRoleHotel();
    $mine = staffRoleFor($hotel);
    staffRoleFor($otherHotel);

    $response = $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/staff-roles')
        ->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('shows how many employees hold a role', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($hotel);
    User::factory()->role(UserRole::EMPLOYEE)->count(2)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/staff-roles/{$role->id}")
        ->assertOk()
        ->assertJsonPath('body.users_count', 2);
});

// update

it('lets an admin change the permissions of a role', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($hotel);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/staff-roles/{$role->id}", ['permissions' => [Permission::BOOKINGS_CREATE->value]])
        ->assertOk()
        ->assertJsonPath('body.name', 'Front Desk')
        ->assertJsonPath('body.permissions', [Permission::BOOKINGS_CREATE->value]);
});

it('lets an admin keep a role name when updating it', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($hotel);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/staff-roles/{$role->id}", ['name' => 'Front Desk'])
        ->assertOk();
});

// destroy

it('refuses to delete a role still assigned to an employee', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($hotel);
    User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/staff-roles/{$role->id}")
        ->assertStatus(422);

    expect($role->fresh()->trashed())->toBeFalse();
});

it('deletes a role nobody holds', function () {
    [$admin, $hotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($hotel);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/staff-roles/{$role->id}")
        ->assertOk();

    expect(StaffRole::withTrashed()->find($role->id)->trashed())->toBeTrue();
});

// access

it('does not let an admin reach another hotel staff role', function () {
    [$admin] = adminWithStaffRoleHotel();
    [, $otherHotel] = adminWithStaffRoleHotel();
    $role = staffRoleFor($otherHotel);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/staff-roles/{$role->id}")->assertForbidden();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/staff-roles/{$role->id}", ['permissions' => Permission::cases()])->assertForbidden();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/staff-roles/{$role->id}")->assertForbidden();

    expect($role->fresh()->grantedPermissions())->toBe([Permission::GUESTS_VIEW]);
});

it('keeps staff roles and the permission list away from employees, whatever their role grants', function () {
    [, $hotel] = adminWithStaffRoleHotel();
    $everything = staffRoleFor($hotel, [
        'name' => 'Everything',
        'permissions' => array_map(fn (Permission $permission) => $permission->value, Permission::cases()),
    ]);
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $everything->id]);

    $this->withHeaders(staffRoleApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/staff-roles')->assertForbidden();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/staff-roles', ['name' => 'Mine', 'permissions' => []])->assertForbidden();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/staff-roles/{$everything->id}", ['name' => 'Renamed'])->assertForbidden();

    $this->withHeaders(staffRoleApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/permissions')->assertForbidden();
});

// permission list

it('lists every permission grouped by resource, with the employee defaults', function () {
    [$admin] = adminWithStaffRoleHotel();

    $response = $this->withHeaders(staffRoleApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/permissions')
        ->assertOk()
        ->assertJsonPath('body.employee_defaults', array_map(
            fn (Permission $permission) => $permission->value,
            Permission::employeeDefaults(),
        ));

    $groups = collect($response->json('body.groups'));
    $guests = $groups->firstWhere('group', 'guests');

    expect($groups->flatMap(fn (array $group) => collect($group['permissions'])->pluck('value'))->count())
        ->toBe(count(Permission::cases()))
        ->and($guests['label'])->toBe('Guests')
        ->and(collect($guests['permissions'])->pluck('action')->all())->toBe(['view', 'create', 'update', 'delete']);
});
