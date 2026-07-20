<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('imports reservations from an uploaded csv file', function () {
    putenv('API_KEY=test-api-key');

    $user = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent(
        'reservations.csv',
        "reservation_id,hotel_name,guest_name,room_number,arrival_date,departure_date,status,adults,children,booking_value,currency\nABC123,Demo Hotel,Jane Doe,101,2026-07-20,2026-07-25,confirmed,2,1,250.50,USD\n"
    );

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user)
        ->postJson('/api/reservation/import', [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('imported', 1);

    $this->assertDatabaseHas('reservations', ['reservation_id' => 'ABC123']);
    $this->assertDatabaseHas('hotels', ['name' => 'Demo Hotel']);
    $this->assertDatabaseHas('guests', ['first_name' => 'Jane', 'last_name' => 'Doe']);
});
