<?php

use App\Ai\Tools\CreateReservationTool;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
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

function toolRoomType(Hotel $hotel, string $name, bool $active = true): RoomType
{
    return RoomType::create([
        'hotel_id' => $hotel->id,
        'name' => $name,
        'max_occupancy' => 2,
        'adult_capacity' => 2,
        'child_capacity' => 0,
        'base_price' => 100,
        'is_active' => $active,
    ]);
}

it('creates a guest and reservation from admin-provided details', function () {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');
    $tool = new CreateReservationTool($hotel);

    $result = $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'guest_first_name' => 'Youssef',
        'guest_last_name' => 'Kamal',
        'guest_email' => 'youssef.kamal@example.test',
        'rooms' => [['room_type' => 'Deluxe']],
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
    expect($data['reservation_rooms'])->toHaveCount(1);

    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(1);
});

it('reuses the existing guest for the same phone number', function () {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');
    $tool = new CreateReservationTool($hotel);

    $first = json_decode((string) $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'Deluxe']],
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ])), true);

    $second = json_decode((string) $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'Deluxe']],
        'arrival_date' => '2026-11-01',
        'departure_date' => '2026-11-03',
    ])), true);

    expect($first['guest']['id'])->toBe($second['guest']['id']);
    expect(Guest::count())->toBe(1);
    expect(Reservation::count())->toBe(2);
});

it('creates a separate guest for a different phone number', function () {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');
    $tool = new CreateReservationTool($hotel);

    foreach (['201222333444', '201222333999'] as $phone) {
        $tool->handle(new Request([
            'guest_phone' => $phone,
            'rooms' => [['room_type' => 'Deluxe']],
            'arrival_date' => '2026-09-01',
            'departure_date' => '2026-09-04',
        ]));
    }

    expect(Guest::count())->toBe(2);
});

it('rejects a room that does not belong to the resolved hotel', function () {
    $hotel = hotelForReservationTool();
    $otherHotel = hotelForReservationTool();
    $foreignRoom = Room::create([
        'hotel_id' => $otherHotel->id,
        'room_type_id' => roomTypeIdFor($otherHotel),
        'room_number' => '101',
    ]);

    $tool = new CreateReservationTool($hotel);

    $result = $tool->handle(new Request([
        'guest_phone' => '201222333444',
        'room_number' => $foreignRoom->room_number,
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect((string) $result)->toBe('Room 101 does not exist in this hotel.');
    expect(Reservation::count())->toBe(0);
});

// Booking by room type (US5)

it('books rooms by type name and quantity, audited as the AI agent', function () {
    $hotel = hotelForReservationTool();
    $deluxe = toolRoomType($hotel, 'Deluxe');

    $data = json_decode((string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'deluxe', 'quantity' => 2]],
        'adults' => 2,
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ])), true);

    $lines = ReservationRoom::where('reservation_id', $data['id'])->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('room_type_id')->unique()->all())->toBe([$deluxe->id]);

    $events = EventLog::where('event_type', 'reservation_room.created')->whereIn('subject_id', $lines->pluck('id'))->get();
    expect($events)->toHaveCount(2)
        ->and($events->every(fn ($event) => ($event->actor_kind->value ?? $event->actor_kind) === 'ai_agent'))->toBeTrue();
});

it('lists the available types for an unknown or inactive type, and books nothing', function (string $name) {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');
    toolRoomType($hotel, 'Suite');
    toolRoomType($hotel, 'Retired', active: false);

    $result = (string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => $name]],
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect($result)->toBe("Unknown room type {$name}. Available: Deluxe, Suite.")
        ->and(Reservation::count())->toBe(0);
})->with(['Penthouse', 'Retired']);

it('rejects an over-capacity party and cannot override it', function () {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');

    $result = (string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'Deluxe']],
        'adults' => 5,
        'capacity_override' => true,
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect($result)->toContain('is larger than the booked rooms hold')
        ->and(Reservation::count())->toBe(0);
});

it('rejects a room number on a line with a quantity above 1', function () {
    $hotel = hotelForReservationTool();
    $deluxe = toolRoomType($hotel, 'Deluxe');
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $deluxe->id, 'room_number' => '101']);

    $result = (string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'rooms' => [['room_type' => 'Deluxe', 'quantity' => 2, 'room_number' => '101']],
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect($result)->toBe('A room number can only be given for a single room, not for a quantity above 1.')
        ->and(Reservation::count())->toBe(0);
});

it('books the legacy room_number as one line of that room type', function () {
    $hotel = hotelForReservationTool();
    $deluxe = toolRoomType($hotel, 'Deluxe');
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $deluxe->id, 'room_number' => '101']);

    $data = json_decode((string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'room_number' => '101',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ])), true);

    $line = ReservationRoom::where('reservation_id', $data['id'])->sole();

    expect($line->room_type_id)->toBe($deluxe->id)
        ->and($line->room_id)->toBe($room->id);
});

it('asks for the room type when neither rooms nor a room number is given', function () {
    $hotel = hotelForReservationTool();
    toolRoomType($hotel, 'Deluxe');

    $result = (string) (new CreateReservationTool($hotel))->handle(new Request([
        'guest_phone' => '201222333444',
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ]));

    expect($result)->toBe('Which room type should I book?')
        ->and(Reservation::count())->toBe(0)
        ->and(Guest::count())->toBe(0);
});
