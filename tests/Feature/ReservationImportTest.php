<?php

use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
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
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => '101', 'room_type' => 'standard']);

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
    expect(Reservation::where('room_id', $room->id)->exists())->toBeTrue();
});

it('creates a room when the room_number in the file does not exist yet', function () {
    [$admin, $hotel] = adminWithHotel();

    $file = importCsv([['555-0300', 'New', 'Room', '204', '2026-09-01', '2026-09-04']]);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation/import', ['file' => $file]);

    $response->assertOk()->assertJsonPath('body.imported', 1);

    $room = Room::where('hotel_id', $hotel->id)->where('room_number', '204')->first();
    expect($room)->not->toBeNull();
    expect(Reservation::where('room_id', $room->id)->exists())->toBeTrue();
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
