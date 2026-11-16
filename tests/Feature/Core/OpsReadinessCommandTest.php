<?php

use App\Models\AiModelPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Spec 017 (FR-032, contracts/operations-interfaces.md): ops:readiness names
| every configuration problem, exits 1 when there is one, and never prints a
| secret's value.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');

    app()['env'] = 'production';
    config([
        'app.api_key' => 'test-api-key',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'services.whatsapp.verify_token' => 'verify-secret-value',
        'services.whatsapp.app_secret' => 'app-secret-value',
        'ai.default' => 'openai',
        'ai.default_for_embeddings' => 'gemini',
        'ai.providers.openai.key' => 'openai-secret-value',
        'ai.providers.gemini.key' => 'gemini-secret-value',
        'ai_cost.daily_ceiling_usd' => 50,
        'health.token' => str_repeat('h', 40),
        'sentry.dsn' => 'https://public@sentry.example.test/1',
        'ops.alert_email' => 'ops@example.test',
        'mail.default' => 'smtp',
    ]);

    foreach (['openai', 'gemini'] as $provider) {
        AiModelPrice::create([
            'provider' => $provider, 'model' => "{$provider}-model", 'input_price_per_million' => 1,
            'output_price_per_million' => 1, 'currency' => 'USD', 'effective_from' => now()->subDay(),
        ]);
    }
});

afterEach(function () {
    app()['env'] = 'testing';
});

it('passes a complete production configuration', function () {
    $this->artisan('ops:readiness')->expectsOutputToContain('Ready.')->assertSuccessful();
});

it('names each problem and fails', function (Closure $break, string $check) {
    $break();

    $this->artisan('ops:readiness')->expectsOutputToContain("✗ {$check}")->assertFailed();
})->with([
    'not production' => [fn () => app()['env'] = 'staging', 'app.env'],
    'debug on' => [fn () => config(['app.debug' => true]), 'app.env'],
    'no API key' => [fn () => config(['app.api_key' => null]), 'app.secrets'],
    'no WhatsApp secret' => [fn () => config(['services.whatsapp.app_secret' => null]), 'app.secrets'],
    'no AI key' => [fn () => config(['ai.providers.openai.key' => null]), 'ai.provider'],
    'unpriced provider' => [fn () => AiModelPrice::where('provider', 'gemini')->delete(), 'ai.models'],
    'no ceiling' => [fn () => config(['ai_cost.daily_ceiling_usd' => 0]), 'ai.ceilings'],
    'queue unreachable' => [fn () => config(['queue.connections.database.connection' => 'missing-connection']), 'infra.queue'],
    'short health token' => [fn () => config(['health.token' => 'short']), 'ops.health_token'],
    'no Sentry DSN' => [fn () => config(['sentry.dsn' => null]), 'ops.sentry'],
    'no alert address' => [fn () => config(['ops.alert_email' => null]), 'ops.alerts'],
    'log mailer' => [fn () => config(['mail.default' => 'log']), 'ops.alerts'],
]);

it('flags a lane waiting past its limit, except right after a deploy', function () {
    DB::table('jobs')->insert([
        'queue' => 'realtime', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->getTimestamp() - 120, 'created_at' => now()->getTimestamp() - 120,
    ]);

    $this->artisan('ops:readiness')->expectsOutputToContain('✗ infra.lanes')->assertFailed();
    $this->artisan('ops:readiness --deploy')->expectsOutputToContain('infra.lanes: skipped')->assertSuccessful();
});

it('never prints a secret value', function () {
    config(['ops.alert_email' => null]);

    $this->artisan('ops:readiness')
        ->doesntExpectOutputToContain('verify-secret-value')
        ->doesntExpectOutputToContain('app-secret-value')
        ->doesntExpectOutputToContain('openai-secret-value')
        ->doesntExpectOutputToContain(str_repeat('h', 40))
        ->assertFailed();
});
