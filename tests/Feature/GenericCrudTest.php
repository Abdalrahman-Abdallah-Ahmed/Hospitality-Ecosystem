<?php

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use App\Support\RequestRules\ModelColumnRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function asUser(User $user)
{
    return test()->withHeader('X-API-KEY', 'test-api-key')->actingAs($user, 'sanctum');
}

it('creates a hotel with generic store rules derived from the hotels table', function () {
    $owner = User::factory()->create();

    $response = asUser($owner)->postJson('/api/hotel', [
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.name', 'Grand Harbor Hotel')
        ->assertJsonPath('body.owner_id', $owner->id);

    expect(Hotel::where('slug', 'grand-harbor-hotel')->exists())->toBeTrue();
});

it('rejects a hotel missing a required column and an invalid boolean cast', function () {
    $owner = User::factory()->create();

    $response = asUser($owner)->postJson('/api/hotel', [
        'slug' => 'missing-name-hotel',
        'is_active' => 'not-a-boolean',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'is_active']);
});

it('only lists hotels owned by the authenticated user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    Hotel::create(['owner_id' => $owner->id, 'name' => 'Mine', 'slug' => 'mine', 'currency' => 'USD']);
    Hotel::create(['owner_id' => $other->id, 'name' => 'Theirs', 'slug' => 'theirs', 'currency' => 'USD']);

    $response = asUser($owner)->getJson('/api/hotel');

    $response->assertOk();
    expect($response->json('body.data'))->toHaveCount(1);
    expect($response->json('body.data.0.slug'))->toBe('mine');
});

it('updates a hotel partially via the generic update request', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $owner->id, 'name' => 'Old Name', 'slug' => 'old-slug', 'currency' => 'USD']);

    $response = asUser($owner)->putJson("/api/hotel/{$hotel->id}", [
        'name' => 'New Name',
    ]);

    $response->assertOk()->assertJsonPath('body.name', 'New Name');
    expect($hotel->fresh()->slug)->toBe('old-slug');
});

it('does not flag a hotel unique slug rule against itself on update', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $owner->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $response = asUser($owner)->putJson("/api/hotel/{$hotel->id}", [
        'slug' => 'harbor',
    ]);

    $response->assertOk();
});

it('restores a soft-deleted hotel instead of throwing a duplicate-key error on recreation', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $owner->id, 'name' => 'Old Name', 'slug' => 'reused-slug', 'currency' => 'USD']);
    $hotel->delete();

    expect(Hotel::withTrashed()->count())->toBe(1);

    $response = asUser($owner)->postJson('/api/hotel', [
        'name' => 'New Name',
        'slug' => 'reused-slug',
        'currency' => 'EUR',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.id', $hotel->id)
        ->assertJsonPath('body.name', 'New Name');

    expect(Hotel::count())->toBe(1);
    expect(Hotel::withTrashed()->count())->toBe(1);
    expect($hotel->fresh()->trashed())->toBeFalse();
});

it('rejects recreating a hotel whose slug belongs to a soft-deleted hotel owned by someone else', function () {
    $originalOwner = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $originalOwner->id, 'name' => 'Original', 'slug' => 'shared-slug', 'currency' => 'USD']);
    $hotel->delete();

    $newOwner = User::factory()->create();

    $response = asUser($newOwner)->postJson('/api/hotel', [
        'name' => 'Hijack Attempt',
        'slug' => 'shared-slug',
        'currency' => 'USD',
    ]);

    $response->assertStatus(422)->assertJsonPath('message', 'The slug has already been taken.');
    expect(Hotel::withTrashed()->where('owner_id', $newOwner->id)->exists())->toBeFalse();
});

it('rejects a hotel update for a non-owner', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $owner->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $response = asUser($stranger)->putJson("/api/hotel/{$hotel->id}", [
        'name' => 'Hijacked',
    ]);

    $response->assertStatus(403);
});

it('creates a service for the caller hotel with json/decimal columns validated generically', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create(['owner_id' => $owner->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $response = asUser($owner)->postJson('/api/service', [
        'hotel_id' => $hotel->id,
        'name' => 'Spa Day',
        'price' => 120.5,
        'is_active' => true,
        'availability' => ['mon', 'tue'],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.name', 'Spa Day')
        ->assertJsonPath('body.availability', ['mon', 'tue']);
});

it('rejects a service for a hotel the caller does not own', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $otherHotel = Hotel::create(['owner_id' => $otherOwner->id, 'name' => 'Other', 'slug' => 'other', 'currency' => 'USD']);

    $response = asUser($owner)->postJson('/api/service', [
        'hotel_id' => $otherHotel->id,
        'name' => 'Spa Day',
    ]);

    $response->assertStatus(403);
});

it('builds reservation update rules that accept a valid enum status and ignore the record itself for uniqueness', function () {
    $hotel = Hotel::create(['owner_id' => User::factory()->create()->id, 'name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-1', 'channel' => 'booking_com']);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-TEST0001',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]);

    $rules = ModelColumnRules::forUpdate(Reservation::class, $reservation);

    $valid = Validator::make([
        'status' => 'confirmed',
        'adults' => 2,
        'reservation_id' => 'RES-TEST0001',
    ], $rules);

    expect($valid->fails())->toBeFalse();

    $invalid = Validator::make(['status' => 'not-a-real-status'], $rules);

    expect($invalid->fails())->toBeTrue();
    expect($invalid->errors()->has('status'))->toBeTrue();

    $reservation->update($valid->validated());
    expect($reservation->fresh()->adults)->toBe(2);
});
