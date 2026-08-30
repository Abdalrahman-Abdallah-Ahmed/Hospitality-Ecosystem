<?php

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Services\GuestIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function identityTestHotel(): Hotel
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Identity Test Hotel',
        'slug' => 'identity-test-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

it('computes the same identity hash for the same email regardless of case or whitespace', function () {
    $hotel = identityTestHotel();

    $a = Guest::create(['hotel_id' => $hotel->id, 'email' => 'Ann.Lee@Example.com']);
    $b = Guest::create(['hotel_id' => $hotel->id, 'email' => ' ann.lee@example.com ']);

    expect($a->identity_hash)->not->toBeNull();
    expect($a->identity_hash)->toBe($b->identity_hash);
});

it('computes the same identity hash for the same phone number regardless of formatting', function () {
    $hotel = identityTestHotel();

    $a = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 115 179 3758']);
    $b = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '201151793758']);

    expect($a->identity_hash)->toBe($b->identity_hash);
});

it('finds an existing guest by email for reuse when the same person books via a different channel', function () {
    $service = app(GuestIdentityService::class);
    $hotel = identityTestHotel();

    // The exact scenario this exists for: the same person booked once
    // directly and once through Booking.com, at the same hotel.
    $direct = Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    $found = $service->findExistingGuest($hotel->id, 'ann@example.com', null);

    expect($found?->id)->toBe($direct->id);
});

it('finds an existing guest by phone number, formatting differences aside', function () {
    $service = app(GuestIdentityService::class);
    $hotel = identityTestHotel();

    $direct = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 115 179 3758']);

    $found = $service->findExistingGuest($hotel->id, null, '201151793758');

    expect($found?->id)->toBe($direct->id);
});

it('does not find a matching guest from a different hotel', function () {
    $service = app(GuestIdentityService::class);
    $hotelA = identityTestHotel();
    $hotelB = identityTestHotel();

    Guest::create(['hotel_id' => $hotelB->id, 'email' => 'ann@example.com']);

    // Same email, but a different hotel — not this hotel's business to see.
    expect($service->findExistingGuest($hotelA->id, 'ann@example.com', null))->toBeNull();
});

it('does not match on a shared surname alone', function () {
    $service = app(GuestIdentityService::class);
    $hotel = identityTestHotel();

    Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Ann', 'last_name' => 'Lee']);

    // No email/phone given to match against, so nothing to fingerprint.
    expect($service->findExistingGuest($hotel->id, null, null))->toBeNull();
});

it('does not treat two different emails as a match', function () {
    $service = app(GuestIdentityService::class);
    $hotel = identityTestHotel();

    Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);

    expect($service->findExistingGuest($hotel->id, 'someone-else@example.com', null))->toBeNull();
});

it('finds a soft-deleted guest too, so the caller can restore instead of duplicating', function () {
    $service = app(GuestIdentityService::class);
    $hotel = identityTestHotel();

    $trashed = Guest::create(['hotel_id' => $hotel->id, 'email' => 'ann@example.com']);
    $trashed->delete();

    $found = $service->findExistingGuest($hotel->id, 'ann@example.com', null);

    expect($found?->id)->toBe($trashed->id);
    expect($found->trashed())->toBeTrue();
});
