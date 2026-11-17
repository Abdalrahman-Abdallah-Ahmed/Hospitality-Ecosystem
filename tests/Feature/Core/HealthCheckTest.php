<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Spec 017 (FR-026, contracts/health-api.md): GET /up says only that the app
| boots; GET /api/health needs the X-Health-Token and checks the database,
| the queue and the cache, without ever saying why something failed.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key', 'health.token' => str_repeat('h', 40)]);
});

function healthCheck(object $test, ?string $token)
{
    return $test->withHeaders($token === null ? [] : ['X-Health-Token' => $token])->getJson('/api/health');
}

it('refuses a caller without the right token', function (?string $token) {
    healthCheck($this, $token)->assertUnauthorized()->assertJson(['message' => 'Unauthorized.', 'body' => null]);
})->with(['no header' => null, 'wrong token' => 'not-the-token', 'empty' => '']);

it('refuses everyone when no token is configured', function () {
    config(['health.token' => null]);

    healthCheck($this, '')->assertUnauthorized();
    healthCheck($this, 'anything')->assertUnauthorized();
});

it('reports healthy when the database, queue and cache answer', function () {
    healthCheck($this, str_repeat('h', 40))
        ->assertOk()
        ->assertExactJson([
            'message' => 'Healthy.',
            'code' => 200,
            'body' => ['status' => 'healthy', 'checks' => ['database' => 'ok', 'queue' => 'ok', 'cache' => 'ok']],
        ]);
});

it('reports the failing part, and nothing about why', function () {
    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis://secret-host:6379 refused the connection'));

    $response = healthCheck($this, str_repeat('h', 40))->assertStatus(503);

    expect($response->json('body'))->toBe(['status' => 'unhealthy', 'checks' => ['database' => 'ok', 'queue' => 'ok', 'cache' => 'fail']])
        ->and($response->getContent())->not->toContain('secret-host')
        ->and($response->getContent())->not->toContain('RuntimeException');
});

it('fails the queue check when the queue connection is unreachable', function () {
    config(['queue.connections.database.connection' => 'missing-connection']);

    expect(healthCheck($this, str_repeat('h', 40))->assertStatus(503)->json('body.checks.queue'))->toBe('fail');
});

it('keeps the public check open and plain', function () {
    $this->get('/up')->assertOk();
});
