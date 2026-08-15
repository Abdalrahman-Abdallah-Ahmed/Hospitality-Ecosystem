<?php

use App\Ai\Tools\CreateReservationTool;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function hotelForReservationTool(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$owner->id,
        'currency' => 'USD',
    ]);
}

it('creates a guest and reservation from admin-provided details', function () {
    $hotel = hotelForReservationTool();
    $tool = new CreateReservationTool($hotel);

    $result = $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'guest_first_name' => 'Youssef',
        'guest_last_name' => 'Kamal',
        'guest_email' => 'youssef.kamal@example.test',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => 'confirmed',
        'reservation_value' => 500.00,
    ]));

    $data = json_decode((string) $result, true);

    expect($data['hotel_id'])->toBe($hotel->id);
    expect($data['source'])->toBe('whatsapp');
    expect($data['guest']['phone_number'])->toBe('201222333444');
    expect($data['guest']['first_name'])->toBe('Youssef');

    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(1);
});

it('reuses the existing guest for the same phone number', function () {
    $hotel = hotelForReservationTool();
    $tool = new CreateReservationTool($hotel);

    $first = json_decode((string) $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ])), true);

    $second = json_decode((string) $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'arrival_date' => '2026-11-01',
        'departure_date' => '2026-11-03',
    ])), true);

    expect($first['guest']['id'])->toBe($second['guest']['id']);
    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(2);
});

it('creates a separate guest for a different phone number', function () {
    $hotel = hotelForReservationTool();
    $tool = new CreateReservationTool($hotel);

    $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    $tool->handle(new Request([
        'guest_phone' => '201222333999',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect(Guest::count())->toBe(2);
});

it('rejects a room that does not belong to the resolved hotel', function () {
    $hotel = hotelForReservationTool();
    $otherHotel = hotelForReservationTool();
    $foreignRoom = Room::create([
        'hotel_id' => $otherHotel->id,
        'room_number' => '101',
    ]);

    $tool = new CreateReservationTool($hotel);

    $result = $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'room_number' => $foreignRoom->room_number,
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect((string) $result)->toBe('The selected room does not belong to this hotel.');
    expect(Reservation::count())->toBe(0);
});
