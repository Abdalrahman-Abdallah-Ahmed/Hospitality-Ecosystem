<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\SenderRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function senderRecognitionService(): SenderRecognitionService
{
    return app(SenderRecognitionService::class);
}

it('recognizes an admin phone number, unpaired by default', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '+201151793758',
    ]);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);

    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->type->value)->toBe('admin');
    expect($recognition->hotelId)->toBe($hotel->id);
    expect($recognition->sender->id)->toBe($user->id);
    expect($recognition->reservation)->toBeNull();
    expect($recognition->devicePaired)->toBeFalse();
});

it('marks an admin as devicePaired once they have an active WhatsAppDevice', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '+201151793758',
    ]);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);

    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '+201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'active',
    ]);

    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->devicePaired)->toBeTrue();
});

it('does not mark an admin as devicePaired when their device is inactive', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '+201151793758',
    ]);
    $hotel = Hotel::create([
        'owner_id' => $user->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel',
        'currency' => 'USD',
    ]);
    $user->update(['hotel_id' => $hotel->id]);

    WhatsAppDevice::create([
        'user_id' => $user->id,
        'phone_number' => '+201151793758',
        'hotel_id' => $hotel->id,
        'wa_user_id' => 'EG.1586110233134033',
        'status' => 'inactive',
    ]);

    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->devicePaired)->toBeFalse();
});

it('recognizes a guest phone number and resolves the hotel through their current reservation', function () {
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Seaside Hotel',
        'slug' => 'seaside-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'phone_number' => '+201151793758',
    ]);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
    ]);

    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->type->value)->toBe('guest');
    expect($recognition->hotelId)->toBe($hotel->id);
    expect($recognition->sender->id)->toBe($guest->id);
    expect($recognition->reservation->id)->toBe($reservation->id);
});

it('resolves the guest hotel through their current reservation instead of their stored hotel_id', function () {
    $owner = User::factory()->create();
    $staleHotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Stale Hotel',
        'slug' => 'stale-hotel',
        'currency' => 'USD',
    ]);
    $currentHotel = Hotel::create([
        'owner_id' => User::factory()->create()->id,
        'name' => 'Current Hotel',
        'slug' => 'current-hotel',
        'currency' => 'USD',
    ]);
    $guest = Guest::create([
        'hotel_id' => $staleHotel->id,
        'phone_number' => '+201151793758',
    ]);
    Reservation::create([
        'hotel_id' => $currentHotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
    ]);

    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->hotelId)->toBe($currentHotel->id);
});

it('recognizes an unrecognized phone number as unknown', function () {
    $recognition = senderRecognitionService()->resolve('+201151793758');

    expect($recognition->type->value)->toBe('unknown');
    expect($recognition->hotelId)->toBeNull();
    expect($recognition->sender)->toBeNull();
    expect($recognition->reservation)->toBeNull();
    expect($recognition->devicePaired)->toBeFalse();
});
