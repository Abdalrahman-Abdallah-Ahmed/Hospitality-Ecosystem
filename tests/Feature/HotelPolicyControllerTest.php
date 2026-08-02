<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function hotelPolicyApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithOwnedHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);

    return [$admin, $hotel];
}

function hotelPolicyFor(Hotel $hotel, array $overrides = []): HotelPolicy
{
    return HotelPolicy::create(array_merge([
        'hotel_id' => $hotel->id,
        'title' => 'Cancellation Policy',
        'content' => 'Free cancellation up to 24 hours before arrival.',
    ], $overrides));
}

// index

it('rejects an unauthenticated index request', function () {
    $this->withHeaders(hotelPolicyApiHeaders())->getJson('/api/hotel-policy')
        ->assertStatus(401);
});

it('rejects a worker from listing hotel policies', function () {
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($worker, 'sanctum')
        ->getJson('/api/hotel-policy')
        ->assertStatus(403);
});

it('only lists hotel policies belonging to the admin own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotel();
    $mine = hotelPolicyFor($hotel, ['title' => 'Mine']);

    [, $otherHotel] = adminWithOwnedHotel();
    hotelPolicyFor($otherHotel, ['title' => 'Theirs']);

    $response = $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/hotel-policy');

    $response->assertOk();
    $data = collect($response->json('body.data'));
    expect($data)->toHaveCount(1);
    expect($data->first()['id'])->toBe($mine->id);
});

// store

it('rejects a worker from creating a hotel policy', function () {
    [, $hotel] = adminWithOwnedHotel();
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/hotel-policy', [
            'hotel_id' => $hotel->id,
            'title' => 'Cancellation Policy',
            'content' => 'Free cancellation up to 24 hours before arrival.',
        ])
        ->assertStatus(403);
});

it('creates a hotel policy scoped to the caller own hotel, ignoring a spoofed hotel_id', function () {
    [$admin, $hotel] = adminWithOwnedHotel();
    [, $otherHotel] = adminWithOwnedHotel();

    $response = $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/hotel-policy', [
            'hotel_id' => $otherHotel->id,
            'title' => 'Cancellation Policy',
            'content' => 'Free cancellation up to 24 hours before arrival.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.hotel_id', $hotel->id);

    expect(HotelPolicy::where('hotel_id', $otherHotel->id)->exists())->toBeFalse();
});

it('rejects an admin without an associated hotel from creating a policy', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    [, $someHotel] = adminWithOwnedHotel();

    $response = $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/hotel-policy', [
            'hotel_id' => $someHotel->id,
            'title' => 'Cancellation Policy',
            'content' => 'Free cancellation up to 24 hours before arrival.',
        ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'User does not have an associated hotel.');
});

// update

it('lets an admin update a policy belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);

    $response = $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/hotel-policy/{$policy->id}", ['title' => 'Updated Policy']);

    $response->assertOk()->assertJsonPath('body.title', 'Updated Policy');
});

it('rejects an admin updating a policy belonging to a different hotel', function () {
    [, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);

    [$stranger] = adminWithOwnedHotel();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($stranger, 'sanctum')
        ->putJson("/api/hotel-policy/{$policy->id}", ['title' => 'Hijacked'])
        ->assertStatus(403);

    expect($policy->fresh()->title)->not->toBe('Hijacked');
});

it('ignores an attempt to reassign a policy to a different hotel on update', function () {
    [$admin, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);
    [, $otherHotel] = adminWithOwnedHotel();

    $response = $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/hotel-policy/{$policy->id}", ['hotel_id' => $otherHotel->id]);

    $response->assertOk();
    expect($policy->fresh()->hotel_id)->toBe($hotel->id);
});

// destroy

it('lets an admin delete a policy belonging to their own hotel', function () {
    [$admin, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($admin, 'sanctum')
        ->deleteJson("/api/hotel-policy/{$policy->id}")
        ->assertOk();

    expect(HotelPolicy::find($policy->id))->toBeNull();
});

it('rejects an admin deleting a policy belonging to a different hotel', function () {
    [, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);

    [$stranger] = adminWithOwnedHotel();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($stranger, 'sanctum')
        ->deleteJson("/api/hotel-policy/{$policy->id}")
        ->assertStatus(403);

    expect(HotelPolicy::find($policy->id))->not->toBeNull();
});

// super admin bypass

it('lets a super admin update a hotel policy belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/hotel-policy/{$policy->id}", ['title' => 'Updated by Super Admin'])
        ->assertOk()
        ->assertJsonPath('body.title', 'Updated by Super Admin');
});

it('lets a super admin delete a hotel policy belonging to any hotel', function () {
    [, $hotel] = adminWithOwnedHotel();
    $policy = hotelPolicyFor($hotel);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(hotelPolicyApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/hotel-policy/{$policy->id}")
        ->assertOk();

    expect(HotelPolicy::find($policy->id))->toBeNull();
});
