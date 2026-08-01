<?php

use App\Enums\UserRole;
use App\Models\Hotel;
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

// store

it('rejects a non-super-admin from creating a hotel', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();

    $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/hotel', [
            'owner_id' => $admin->id,
            'name' => 'Grand Harbor Hotel',
            'slug' => 'grand-harbor-hotel',
            'currency' => 'USD',
        ])
        ->assertStatus(403);
});

it('lets a super admin create a hotel for another user', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();

    $response = $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/hotel', [
            'owner_id' => $owner->id,
            'name' => 'Grand Harbor Hotel',
            'slug' => 'grand-harbor-hotel',
            'currency' => 'USD',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.owner_id', $owner->id);

    expect(Hotel::where('slug', 'grand-harbor-hotel')->exists())->toBeTrue();
});

it('rejects a hotel missing required fields', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->postJson('/api/hotel', ['slug' => 'no-name-hotel'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

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
    $worker = User::factory()->role(UserRole::WORKER)->create();
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

it('lets a super admin update a hotel they do not own', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/hotel/{$hotel->id}", ['name' => 'Reassigned'])
        ->assertOk()
        ->assertJsonPath('body.name', 'Reassigned');
});

// destroy

it('rejects the owning admin from deleting their own hotel', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($admin);

    $this->withHeaders(hotelApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/hotel/{$hotel->id}")
        ->assertStatus(403);

    expect(Hotel::find($hotel->id))->not->toBeNull();
});

it('lets a super admin delete any hotel', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = hotelFor($owner);

    $this->withHeaders(hotelApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/hotel/{$hotel->id}")
        ->assertOk();

    expect(Hotel::find($hotel->id))->toBeNull();
    expect(Hotel::withTrashed()->find($hotel->id))->not->toBeNull();
});
