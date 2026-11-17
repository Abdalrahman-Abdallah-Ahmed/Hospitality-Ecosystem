<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.api_key' => 'test-api-key']);
});

function apiKeyRegistrationPayload(): array
{
    return [
        'name' => 'Test User',
        'email' => 'test-'.uniqid().'@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'hotel' => [
            'name' => 'Test Hotel '.uniqid(),
            'city' => 'Cairo',
        ],
    ];
}

it('rejects api requests without an api key', function () {
    $this->postJson('/api/register', apiKeyRegistrationPayload())
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects api requests with the wrong api key', function () {
    $this->withHeader('X-API-KEY', 'not-the-key')
        ->postJson('/api/register', apiKeyRegistrationPayload())
        ->assertStatus(401);
});

it('accepts api requests with a valid api key', function () {
    $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', apiKeyRegistrationPayload())
        ->assertStatus(201);
});

it('reads the api key from config, so it still works once config is cached', function () {
    // After `php artisan optimize`, env() returns null outside config files.
    // A key that only lived in the process environment must not be what the
    // check compares against.
    putenv('API_KEY=a-different-environment-key');

    try {
        $this->withHeader('X-API-KEY', 'test-api-key')
            ->postJson('/api/register', apiKeyRegistrationPayload())
            ->assertStatus(201);
    } finally {
        putenv('API_KEY');
    }
});

it('skips the check when no key is configured in a local or testing environment', function () {
    config(['app.api_key' => null]);

    $this->postJson('/api/register', apiKeyRegistrationPayload())
        ->assertStatus(201);
});

it('refuses every request when no key is configured in production', function () {
    config(['app.api_key' => null]);
    $this->app['env'] = 'production';

    $this->postJson('/api/register', apiKeyRegistrationPayload())
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('does not accept the api key from the query string', function () {
    $this->postJson('/api/register?api_key=test-api-key', apiKeyRegistrationPayload())
        ->assertStatus(401);
});
