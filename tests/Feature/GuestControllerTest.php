<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function guestApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithGuestHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

it('creates a guest for the admin own hotel', function () {
    [$admin, $hotel] = adminWithGuestHotel();

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'first_name' => 'Youssef',
            'channel' => 'booking_com',
            'external_id' => 'booking-ext-982',
        ]);

    $response->assertStatus(201)->assertJsonPath('body.first_name', 'Youssef');
    expect(Guest::where('external_id', 'booking-ext-982')->exists())->toBeTrue();
});

it('restores a soft-deleted guest instead of throwing a duplicate-key error on recreation', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => 'Old Name',
        'channel' => 'booking_com',
        'external_id' => 'booking-ext-982',
    ]);
    $guest->delete();

    expect(Guest::withTrashed()->count())->toBe(1);

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'first_name' => 'New Name',
            'channel' => 'booking_com',
            'external_id' => 'booking-ext-982',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.id', $guest->id)
        ->assertJsonPath('body.first_name', 'New Name');

    expect(Guest::count())->toBe(1);
    expect(Guest::withTrashed()->count())->toBe(1);
    expect($guest->fresh()->trashed())->toBeFalse();
});

it('rejects creating a guest that duplicates an already active guest', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => 'Existing',
        'channel' => 'booking_com',
        'external_id' => 'booking-ext-982',
    ]);

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'first_name' => 'Duplicate',
            'channel' => 'booking_com',
            'external_id' => 'booking-ext-982',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'A guest with this channel and external id already exists.');

    expect(Guest::count())->toBe(1);
});

it('allows creating multiple guests without channel or external id', function () {
    [$admin, $hotel] = adminWithGuestHotel();

    $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', ['hotel_id' => $hotel->id, 'first_name' => 'Walk-in One'])
        ->assertStatus(201);

    $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', ['hotel_id' => $hotel->id, 'first_name' => 'Walk-in Two'])
        ->assertStatus(201);

    expect(Guest::count())->toBe(2);
});

// show

it('lets a super admin view a guest belonging to any hotel', function () {
    [, $hotel] = adminWithGuestHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Youssef', 'channel' => 'booking_com', 'external_id' => 'ext-1']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(guestApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->getJson("/api/guest/{$guest->id}")
        ->assertOk()
        ->assertJsonPath('body.id', $guest->id);
});

// update

it('lets a super admin update a guest belonging to any hotel', function () {
    [, $hotel] = adminWithGuestHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Old Name', 'channel' => 'booking_com', 'external_id' => 'ext-1']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(guestApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['first_name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('body.first_name', 'New Name');
});

// destroy

it('lets a super admin delete a guest belonging to any hotel', function () {
    [, $hotel] = adminWithGuestHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Youssef', 'channel' => 'booking_com', 'external_id' => 'ext-1']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $this->withHeaders(guestApiHeaders())->actingAs($superAdmin, 'sanctum')
        ->deleteJson("/api/guest/{$guest->id}")
        ->assertOk();

    expect(Guest::find($guest->id))->toBeNull();
});
