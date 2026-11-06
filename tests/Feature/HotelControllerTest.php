<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function hotelApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function hotelFor(User $owner, array $overrides = []): Hotel
{
    return Hotel::create(array_merge([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$owner->id,
        'currency' => 'USD',
    ], $overrides));
}

// index

it('rejects an unauthenticated index request', function () {
    $this->withHeaders(hotelApiHeaders())->getJson('/api/hotel')
        ->assertStatus(401);
});

it('rejects a non-super-admin from listing hotels', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    hotelFor($admin);

    $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/hotel')
        ->assertStatus(403);
});

it('lets a super admin list every hotel regardless of owner', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $ownerA = User::factory()->role(UserRole::ADMIN)->create();
    $ownerB = User::factory()->role(UserRole::ADMIN)->create();
    hotelFor($ownerA);
    hotelFor($ownerB);

    $response = $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson('/api/hotel');

    $response->assertOk();
    expect($response->json('body.data'))->toHaveCount(2);
});

// Creating and deleting a hotel moved to /api/superadmin (spec 023), where
// Superadmin/HotelAdministrationTest covers them.

it('has no way to create or delete a hotel on the tenant endpoints, for anyone', function (UserRole $role) {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);
    $caller = $role === UserRole::ADMIN ? $owner : User::factory()->role($role)->create();

    $this->withHeaders(hotelApiHeaders())->actingAs($caller, 'sanctum')
        ->postJson('/api/hotel', ['name' => 'New', 'slug' => 'new'])
        ->assertStatus(405);

    $this->withHeaders(hotelApiHeaders())->actingAs($caller, 'sanctum')
        ->deleteJson("/api/hotel/{$hotel->id}")
        ->assertStatus(405);

    expect(Hotel::find($hotel->id))->not->toBeNull()
        ->and(Hotel::where('slug', 'new')->exists())->toBeFalse();
})->with([
    'an admin' => UserRole::ADMIN,
    'a super admin' => UserRole::SUPER_ADMIN,
]);

// show

it('rejects an unauthenticated show request', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($admin);

    $this->withHeaders(hotelApiHeaders())->getJson("/api/hotel/{$hotel->id}")
        ->assertStatus(401);
});

it('lets the owning admin view their own hotel', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($admin);

    $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/hotel/{$hotel->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $hotel->id);
});

it('lets a super admin view any hotel', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/hotel/{$hotel->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $hotel->id);
});

it('rejects an admin viewing a hotel they do not own', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $stranger = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($stranger, 'sanctum')
        ->getJson("/api/hotel/{$hotel->id}")
        ->assertStatus(403);
});

it('rejects a worker from viewing a hotel', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($worker, 'sanctum')
        ->getJson("/api/hotel/{$hotel->id}")
        ->assertStatus(403);
});

// update

it('lets the owning admin update their own hotel', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($admin, ['name' => 'Old Name']);

    $response = $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", ['name' => 'New Name']);

    $response->assertOk()->assertJsonPath('body.name', 'New Name');
});

it('rejects an admin updating a hotel they do not own', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $stranger = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($stranger, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", ['name' => 'Hijacked'])
        ->assertStatus(403);

    expect($hotel->fresh()->name)->not->toBe('Hijacked');
});

it('ignores owner and account on a tenant hotel update, for every caller', function (UserRole $role) {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);
    $account = $hotel->hotel_group_id;
    $elsewhere = HotelGroup::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $caller = $role === UserRole::ADMIN ? $owner : $superAdmin;

    $this->withHeaders(hotelApiHeaders())->actingAs($caller, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", [
            'name' => 'Renamed',
            'hotel_group_id' => $elsewhere->id,
            'owner_id' => $superAdmin->id,
        ])
        ->assertOk()
        ->assertJsonPath('body.name', 'Renamed');

    $fresh = $hotel->fresh();

    expect($fresh->hotel_group_id)->toBe($account)
        ->and($fresh->owner_id)->toBe($owner->id);
})->with([
    'the owning admin' => UserRole::ADMIN,
    'a super admin' => UserRole::SUPER_ADMIN,
]);

it('refuses a slug a soft-deleted hotel holds, without a server error', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($admin);
    hotelFor(User::factory()->role(UserRole::ADMIN)->create(), ['slug' => 'gone'])->delete();

    $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", ['slug' => 'gone'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['slug']);

    expect($hotel->fresh()->slug)->not->toBe('gone');
});

it('lets a super admin update a hotel they do not own', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", ['name' => 'Reassigned'])
        ->assertOk()
        ->assertJsonPath('body.name', 'Reassigned');
});
