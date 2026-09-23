<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function importCsv(array $rows): UploadedFile
{
    $header = 'guest_phone,guest_first_name,guest_last_name,room_number,arrival_date,departure_date';
    $lines = array_map(fn (array $row) => implode(',', $row), $rows);
    $content = implode("\n", [$header, ...$lines]);

    $path = tempnam(sys_get_temp_dir(), 'reservations').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'reservations.csv', 'text/csv', null, true);
}

it('rejects an unauthenticated import request', function () {
    $this->withHeaders(apiHeaders())->postJson('/api/reservation/import')
        ->assertStatus(401);
});

it('rejects a non-admin user from importing reservations', function () {
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(apiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => importCsv([['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']])])
        ->assertStatus(403);
});

it('imports reservations and creates guests scoped to the admin hotel', function () {
    [$admin, $hotel] = adminWithHotel();
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $file = importCsv([
        ['555-0100', 'Ann', 'Lee', '101', '2026-09-01', '2026-09-04'],
        ['555-0200', 'Bob', 'Ray', '', '2026-09-02', '2026-09-05'],
        ['', 'No', 'Phone', '', '2026-09-03', '2026-09-06'],
    ]);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file]);

    $response->assertOk()
        ->assertJsonPath('body.imported', 2)
        ->assertJsonCount(1, 'body.skipped');

    expect(Reservation::where('hotel_id', $hotel->id)->count())->toBe(2);
    expect(Guest::where('hotel_id', $hotel->id)->where('phone_number', '555-0100')->exists())->toBeTrue();
    expect(ReservationRoom::where('room_id', $room->id)->where('room_type_id', $room->room_type_id)->exists())->toBeTrue();
});

it('creates a room when the room_number in the file does not exist yet', function () {
    [$admin, $hotel] = adminWithHotel();

    $file = importCsv([['555-0300', 'New', 'Room', '204', '2026-09-01', '2026-09-04']]);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file]);

    $response->assertOk()->assertJsonPath('body.imported', 1);

    $room = Room::where('hotel_id', $hotel->id)->where('room_number', '204')->first();
    expect($room)->not->toBeNull();
    expect(ReservationRoom::where('room_id', $room->id)->where('room_type_id', $room->room_type_id)->exists())->toBeTrue();

    // The file names no room type, and the hotel has none yet: a default is created.
    expect($room->roomType->name)->toBe(RoomType::DEFAULT_NAME)
        ->and($room->roomType->hotel_id)->toBe($hotel->id);
});

it('does not duplicate a guest matched by phone number on repeat import', function () {
    [$admin, $hotel] = adminWithHotel();

    $file = fn () => importCsv([['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file()])->assertOk();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file()])->assertOk();

    expect(Guest::where('hotel_id', $hotel->id)->where('phone_number', '555-0100')->count())->toBe(1);
    expect(Reservation::where('hotel_id', $hotel->id)->count())->toBe(2);
});

it('recognizes an existing guest even when the phone number is formatted differently', function () {
    [$admin, $hotel] = adminWithHotel();
    $existing = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 115 179 3758']);

    $file = importCsv([['201151793758', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']]);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file]);

    $response->assertOk()->assertJsonPath('body.imported', 1);

    // Same person, differently formatted number — reused, not duplicated.
    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
    expect(Reservation::where('hotel_id', $hotel->id)->where('guest_id', $existing->id)->exists())->toBeTrue();
});

it('skips a row whose reservation id the hotel already uses, writing nothing for it', function () {
    [$admin, $hotel] = adminWithHotel();
    reservationFor($hotel, ['reservation_id' => 'RES-DUP00001']);

    $path = tempnam(sys_get_temp_dir(), 'reservations').'.csv';
    file_put_contents($path, "guest_phone,arrival_date,departure_date,reservation_id\n555-0100,2026-09-01,2026-09-04,RES-DUP00001");

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => new UploadedFile($path, 'reservations.csv', 'text/csv', null, true)])
        ->assertOk()
        ->assertJsonPath('body.imported', 0)
        ->assertJsonPath('body.skipped.0.reason', 'Reservation id RES-DUP00001 already exists.');

    expect(Guest::where('hotel_id', $hotel->id)->where('phone_number', '555-0100')->exists())->toBeFalse();
});

// One line per row (reservation rooms)

function importRawCsv(string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'reservations').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'reservations.csv', 'text/csv', null, true);
}

it('files a row with only a room type as one unassigned line of that type', function () {
    [$admin, $hotel] = adminWithHotel();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => importRawCsv(
            "guest_phone,arrival_date,departure_date,room_type\n555-0100,2026-09-01,2026-09-04,Suite"
        )])
        ->assertOk()
        ->assertJsonPath('body.imported', 1);

    $line = ReservationRoom::sole();

    expect($line->room_id)->toBeNull()
        ->and($line->roomType->name)->toBe('Suite')
        ->and($line->hotel_id)->toBe($hotel->id);
});

it('files a row with neither a room nor a type under the hotel default type', function () {
    [$admin, $hotel] = adminWithHotel();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => importCsv([['555-0100', 'Ann', 'Lee', '', '2026-09-01', '2026-09-04']])])
        ->assertOk();

    expect(ReservationRoom::sole()->room_type_id)->toBe(RoomType::resolveFor($hotel->id)->id);
});

it('records legacy parties as they are, without the capacity check', function () {
    [$admin] = adminWithHotel();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => importRawCsv(
            "guest_phone,arrival_date,departure_date,adults\n555-0100,2026-09-01,2026-09-04,9"
        )])
        ->assertOk()
        ->assertJsonPath('body.imported', 1);

    expect(Reservation::sole()->adults)->toBe(9)
        ->and(ReservationRoom::count())->toBe(1);
});

it('adds no duplicate lines when the same file is imported twice', function () {
    [$admin] = adminWithHotel();
    $file = fn () => importRawCsv("guest_phone,arrival_date,departure_date,reservation_id\n555-0100,2026-09-01,2026-09-04,RES-TWICE001");

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file()])->assertJsonPath('body.imported', 1);
    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file()])->assertJsonPath('body.imported', 0);

    expect(Reservation::count())->toBe(1)
        ->and(ReservationRoom::count())->toBe(1);
});
