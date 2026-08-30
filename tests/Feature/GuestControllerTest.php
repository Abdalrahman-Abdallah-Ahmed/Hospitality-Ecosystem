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

it('ignores an attempt to reassign a guest to a different hotel on update', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Youssef', 'channel' => 'booking_com', 'external_id' => 'ext-1']);
    [, $otherHotel] = adminWithGuestHotel();

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->putJson("/api/guest/{$guest->id}", ['hotel_id' => $otherHotel->id]);

    $response->assertOk();
    expect($guest->fresh()->hotel_id)->toBe($hotel->id);
});

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

// dedup by email/phone across channels

it('reuses the existing guest instead of duplicating when the same email arrives via a different channel', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    $direct = Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'email' => 'ann@example.com',
            'channel' => 'booking_com',
            'external_id' => 'bk-1',
        ]);

    $response->assertCreated();
    expect($response->json('body.id'))->toBe($direct->id);
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('reuses the existing guest instead of duplicating when the same phone number arrives via a different channel', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    $direct = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 115 179 3758']);

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'phone_number' => '201151793758',
            'channel' => 'booking_com',
            'external_id' => 'bk-1',
        ]);

    $response->assertCreated();
    expect($response->json('body.id'))->toBe($direct->id);
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('creates a new guest when the email does not match anyone at this hotel', function () {
    [$admin, $hotel] = adminWithGuestHotel();

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', ['hotel_id' => $hotel->id, 'email' => 'unique@example.com']);

    $response->assertCreated();
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('does not reuse a matching guest from a different hotel', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    [, $otherHotel] = adminWithGuestHotel();
    Guest::create(['hotel_id' => $otherHotel->id, 'email' => 'ann@example.com']);

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', ['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    $response->assertCreated();
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
    // The admin's request scoped TenantContext to their own hotel, so this
    // direct check on the other hotel needs the scope lifted explicitly.
    expect(Guest::withoutGlobalScope('hotel')->where('hotel_id', $otherHotel->id)->count())->toBe(1);
});

it('restores a soft-deleted guest matched by email instead of creating a duplicate', function () {
    [$admin, $hotel] = adminWithGuestHotel();
    $trashed = Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);
    $trashed->delete();

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', ['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    $response->assertCreated();
    expect($response->json('body.id'))->toBe($trashed->id);
    expect($trashed->fresh()->trashed())->toBeFalse();
});

it('reuses an already-active identity match instead of resurrecting an unrelated trashed channel row', function () {
    [$admin, $hotel] = adminWithGuestHotel();

    // The same person, already active under no particular channel.
    $active = Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    // A different, unrelated trashed row that happens to match the exact
    // channel/external_id being resubmitted.
    $trashed = Guest::create([
        'hotel_id' => $hotel->id,
        'email' => 'ann@example.com',
        'channel' => 'booking_com',
        'external_id' => 'bk-1',
    ]);
    $trashed->delete();

    $response = $this->withHeaders(guestApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/guest', [
            'hotel_id' => $hotel->id,
            'email' => 'ann@example.com',
            'channel' => 'booking_com',
            'external_id' => 'bk-1',
        ]);

    $response->assertCreated();
    // Reuses the already-active guest, not the trashed one — restoring the
    // trashed row here would have left two active guests with the same email.
    expect($response->json('body.id'))->toBe($active->id);
    expect($trashed->fresh()->trashed())->toBeTrue();
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
});
