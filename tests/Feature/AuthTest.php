<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a new user and logs them in', function () {
    $response = $this->post('/register', [
        'name' => 'Alice Example',
        'email' => 'alice@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();
    expect(User::where('email', 'alice@example.com')->exists())->toBeTrue();
});

it('logs in an existing user', function () {
    $user = User::factory()->create([
        'email' => 'bob@example.com',
        'password' => bcrypt('Password123!'),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'Password123!',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
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
        ->assertJsonPath('user.email', 'api@example.com');

    expect(User::where('email', 'api@example.com')->exists())->toBeTrue();
});
