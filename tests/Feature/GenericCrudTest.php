<?php

use App\Enums\UserRole;
use App\Models\ActivityCategory;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
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

it('derives a required column and a boolean cast from the hotels table', function () {
    $validator = Validator::make(
        ['slug' => 'missing-name-hotel', 'is_active' => 'not-a-boolean'],
        ModelColumnRules::forCreate(Hotel::class),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('name', 'is_active');
});

it('lists all hotels for a super admin', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $ownerA = User::factory()->role(UserRole::ADMIN)->create();
    $ownerB = User::factory()->role(UserRole::ADMIN)->create();

    Hotel::create(['name' => 'Mine', 'slug' => 'mine', 'currency' => 'USD']);
    Hotel::create(['name' => 'Theirs', 'slug' => 'theirs', 'currency' => 'USD']);

    $response = asUser($superAdmin)->getJson('/api/hotel');

    $response->assertOk();
    expect($response->json('body.data'))->toHaveCount(2);
});

it('limits an admin\'s hotel list to the hotels they can reach', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Mine', 'slug' => 'mine', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);
    Hotel::create(['name' => 'Theirs', 'slug' => 'theirs', 'currency' => 'USD']);

    $response = asUser($owner->fresh())->getJson('/api/hotel');

    $response->assertOk();
    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$hotel->id]);
});

it('updates a hotel partially via the generic update request', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Old Name', 'slug' => 'old-slug', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $response = asUser($owner)->putJson("/api/hotel/{$hotel->id}", [
        'name' => 'New Name',
    ]);

    $response->assertOk()->assertJsonPath('body.name', 'New Name');
    expect($hotel->fresh()->slug)->toBe('old-slug');
});

it('does not flag a hotel unique slug rule against itself on update', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $response = asUser($owner)->putJson("/api/hotel/{$hotel->id}", [
        'slug' => 'harbor',
    ]);

    $response->assertOk();
});

it('rejects a hotel update for an admin who cannot reach the hotel', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $stranger = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);

    $response = asUser($stranger)->putJson("/api/hotel/{$hotel->id}", [
        'name' => 'Hijacked',
    ]);

    $response->assertStatus(403);
});

it('creates an activity for the caller hotel with decimal columns validated generically', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $response = asUser($owner)->postJson('/api/activity', [
        'hotel_id' => $hotel->id,
        'name' => 'Spa Day',
        'price' => 120.5,
        'is_active' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.name', 'Spa Day')
        ->assertJsonPath('body.price', '120.50');
});

it('rejects an activity whose category belongs to a different hotel', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $otherOwner = User::factory()->role(UserRole::ADMIN)->create();
    $otherHotel = Hotel::create(['name' => 'Other', 'slug' => 'other', 'currency' => 'USD']);
    $otherCategory = ActivityCategory::create(['hotel_id' => $otherHotel->id, 'name' => 'Other Category']);

    $response = asUser($owner)->postJson('/api/activity', [
        'hotel_id' => $hotel->id,
        'category_id' => $otherCategory->id,
        'name' => 'Spa Day',
    ]);

    $response->assertStatus(403);
});

it('creates an activity category for the caller hotel', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $response = asUser($owner)->postJson('/api/activity-category', [
        'hotel_id' => $hotel->id,
        'name' => 'Wellness',
        'slug' => 'wellness',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.name', 'Wellness')
        ->assertJsonPath('body.hotel_id', $hotel->id);
});

it('ignores hotel_id on activity category update and keeps its own hotel', function () {
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
    $owner->update(['hotel_id' => $hotel->id]);

    $category = ActivityCategory::create(['hotel_id' => $hotel->id, 'name' => 'Wellness']);
    $otherHotel = Hotel::create(['name' => 'Other', 'slug' => 'other', 'currency' => 'USD']);

    $response = asUser($owner)->putJson("/api/activity-category/{$category->id}", [
        'hotel_id' => $otherHotel->id,
        'name' => 'Wellness & Spa',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.name', 'Wellness & Spa')
        ->assertJsonPath('body.hotel_id', $hotel->id);
});

it('builds reservation update rules that accept a valid enum status and ignore the record itself for uniqueness', function () {
    $hotel = Hotel::create(['name' => 'Harbor', 'slug' => 'harbor', 'currency' => 'USD']);
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
