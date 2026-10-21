<?php

use App\Ai\Tools\GetOwnReservationTool;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\SenderRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

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

it('keeps reservation authority with the primary guest while showing their full party', function () {
    $hotel = Hotel::create([
        'owner_id' => User::factory()->create()->id,
        'name' => 'Party Hotel',
        'slug' => 'party-hotel',
        'currency' => 'USD',
    ]);
    $primary = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+201151793750', 'first_name' => 'Rami']);
    $additional = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+201151793751', 'first_name' => 'Maya']);
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'reservation_id' => 'RES-PARTY-1',
        'arrival_date' => now()->subDay(),
        'departure_date' => now()->addDay(),
        'status' => 'confirmed',
        'adults' => 1,
        'children' => 1,
        'children_ages' => [7],
    ]);
    ReservationGuest::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'guest_id' => $primary->id,
        'is_primary' => true,
    ]);
    ReservationGuest::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'guest_id' => $additional->id,
        'is_primary' => false,
    ]);

    $ownReservation = json_decode((string) (new GetOwnReservationTool($reservation))->handle(new Request([])), true);
    $recognition = senderRecognitionService()->resolve('+201151793751');

    expect($ownReservation['children_ages'])->toBe([7])
        ->and($ownReservation['guests'])->toHaveCount(2)
        ->and($ownReservation['guests'][0]['is_primary'])->toBeTrue()
        ->and($recognition->sender->id)->toBe($additional->id)
        ->and($recognition->reservation)->toBeNull()
        ->and((string) (new GetOwnReservationTool($recognition->reservation))->handle(new Request([])))->toBe('No reservation found for this guest.');
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

function recognitionHotel(string $name): Hotel
{
    return Hotel::create([
        'owner_id' => User::factory()->create()->id,
        'name' => $name,
        'slug' => str($name)->slug().'-'.uniqid(),
        'currency' => 'USD',
    ]);
}

it('recognizes an admin whose number was saved with formatting from the digits Meta sends', function () {
    $hotel = recognitionHotel('Grand Harbor Hotel');
    $user = User::factory()->role(UserRole::ADMIN)->create([
        'phone_number' => '+20 115 179 3758',
        'hotel_id' => $hotel->id,
    ]);

    $recognition = senderRecognitionService()->resolve('201151793758');

    expect($recognition->type->value)->toBe('admin')
        ->and($recognition->sender->id)->toBe($user->id);
});

it('prefers an upcoming stay over a past one when a number is a guest at two hotels', function () {
    $pastHotel = recognitionHotel('Past Hotel');
    $nextHotel = recognitionHotel('Next Hotel');
    $pastGuest = Guest::create(['hotel_id' => $pastHotel->id, 'phone_number' => '201151793758']);
    $nextGuest = Guest::create(['hotel_id' => $nextHotel->id, 'phone_number' => '201151793758']);

    Reservation::create([
        'hotel_id' => $pastHotel->id,
        'guest_id' => $pastGuest->id,
        'reservation_id' => 'PAST-1',
        'arrival_date' => now()->subWeeks(3),
        'departure_date' => now()->subWeeks(3)->addDays(2),
    ]);
    $upcoming = Reservation::create([
        'hotel_id' => $nextHotel->id,
        'guest_id' => $nextGuest->id,
        'reservation_id' => 'NEXT-1',
        'arrival_date' => now()->addWeek(),
        'departure_date' => now()->addWeek()->addDays(2),
    ]);

    $recognition = senderRecognitionService()->resolve('201151793758');

    expect($recognition->sender->id)->toBe($nextGuest->id)
        ->and($recognition->hotelId)->toBe($nextHotel->id)
        ->and($recognition->reservation->id)->toBe($upcoming->id);
});

it('picks the most recently created guest when none of a number\'s guests has a reservation', function () {
    $older = Guest::create(['hotel_id' => recognitionHotel('Older Hotel')->id, 'phone_number' => '201151793758']);
    $this->travel(1)->minutes();
    $newer = Guest::create(['hotel_id' => recognitionHotel('Newer Hotel')->id, 'phone_number' => '201151793758']);

    $recognition = senderRecognitionService()->resolve('201151793758');

    expect($recognition->sender->id)->toBe($newer->id)
        ->and($recognition->sender->id)->not->toBe($older->id);
});
