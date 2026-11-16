<?php

use App\Enums\ReservationChannels;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function channelApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

it('rejects an unauthenticated request', function () {
    $this->withHeaders(channelApiHeaders())->getJson('/api/available-channels')
        ->assertStatus(401);
});

it('returns every reservation channel', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create();

    $this->withHeaders(channelApiHeaders())->actingAs($user, 'sanctum')
        ->getJson('/api/available-channels')
        ->assertOk()
        ->assertJsonPath('message', 'Available channels fetched successfully.')
        ->assertJsonPath('body', [
            'booking_com',
            'expedia',
            'airbnb',
            'agoda',
            'tripadvisor',
            'vrbo',
        ]);
});

it('stays in step with the enum as channels are added', function () {
    $user = User::factory()->role(UserRole::ADMIN)->create();

    $response = $this->withHeaders(channelApiHeaders())->actingAs($user, 'sanctum')
        ->getJson('/api/available-channels')
        ->assertOk();

    expect($response->json('body'))
        ->toBe(array_column(ReservationChannels::cases(), 'value'));
});

it('is open to any authenticated role, not just admins', function () {
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(channelApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/available-channels')
        ->assertOk();
});
