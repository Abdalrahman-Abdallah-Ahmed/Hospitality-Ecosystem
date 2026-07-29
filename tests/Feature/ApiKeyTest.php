<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects api requests without an api key', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test-' . uniqid() . '@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('accepts api requests with a valid api key', function () {
    putenv('API_KEY=test-api-key');

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'hotel' => [
                'name' => 'Test Hotel ' . uniqid(),
                'city' => 'Cairo',
            ],
        ]);

    $response->assertStatus(201);

    putenv('API_KEY');
});
