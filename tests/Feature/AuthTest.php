<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a new user through the api', function () {
    putenv('API_KEY=test-api-key');

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Alice Example',
            'email' => 'alice@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

    $response->assertStatus(201);
    expect(User::where('email', 'alice@example.com')->exists())->toBeTrue();
});

it('logs in an existing user through the api', function () {
    putenv('API_KEY=test-api-key');

    $user = User::factory()->create([
        'email' => 'bob@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('body.user.email', $user->email);
});

it('registers a user through the api endpoint', function () {
    putenv('API_KEY=test-api-key');

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Api User',
            'email' => 'api@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('body.user.email', 'api@example.com');

    expect(User::where('email', 'api@example.com')->exists())->toBeTrue();
});
