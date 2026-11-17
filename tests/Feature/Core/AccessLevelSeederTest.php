<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WhatsAppDevice;
use Database\Seeders\AccessLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Queue::fake();
});

function alsLogin($test, string $email): TestResponse
{
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');

    return $test->withHeaders(apiHeaders())->postJson('/api/login', ['email' => $email, 'password' => AccessLevelSeeder::PASSWORD]);
}

/**
 * A request as this seeded user, with the token a real login gave them.
 */
function alsRequest($test, string $email, string $method, string $uri): TestResponse
{
    $token = alsLogin($test, $email)->assertOk()->json('body.token');

    app('auth')->forgetGuards();

    return $test->json($method, $uri, [], [...apiHeaders(), 'Authorization' => 'Bearer '.$token]);
}

/**
 * @return list<string>
 */
function alsHotels($test, string $email): array
{
    return collect(alsRequest($test, $email, 'GET', '/api/hotel')->assertOk()->json('body.data'))->pluck('slug')->sort()->values()->all();
}

it('gives each login the hotels it is meant to reach', function (string $email, array $hotels) {
    $this->seed(AccessLevelSeeder::class);

    expect(alsHotels($this, $email))->toBe($hotels);
})->with([
    'owner' => ['owner@nile.test', ['nile-aswan', 'nile-cairo', 'nile-luxor']],
    'two hotel admin' => ['admin.multi@nile.test', ['nile-cairo', 'nile-luxor']],
    'one hotel admin' => ['admin.single@nile.test', ['nile-aswan']],
    'suspended default admin' => ['admin@redsea.test', ['red-sea-dahab']],
    'owner of a partly suspended account' => ['owner@redsea.test', ['red-sea-dahab']],
]);

it('gives each employee the permissions of their role', function (string $email, string $allowed, string $refused) {
    $this->seed(AccessLevelSeeder::class);

    alsRequest($this, $email, 'GET', $allowed)->assertOk();
    alsRequest($this, $email, 'GET', $refused)->assertForbidden();
})->with([
    'front desk' => ['frontdesk@nile.test', '/api/guest', '/api/task'],
    'housekeeping' => ['housekeeping@nile.test', '/api/task', '/api/guest'],
    'read only' => ['readonly@nile.test', '/api/guest', '/api/task'],
]);

it('keeps a default employee to the default permissions', function () {
    $this->seed(AccessLevelSeeder::class);

    alsRequest($this, 'employee.norole@nile.test', 'GET', '/api/users')->assertForbidden();
    alsRequest($this, 'employee.norole@nile.test', 'GET', '/api/user')->assertOk()->assertJsonPath('body.staff_role_id', null);
});

it('makes the super admins platform operators', function () {
    $this->seed(AccessLevelSeeder::class);

    alsRequest($this, 'second.superadmin@hospitality.test', 'GET', '/api/superadmin/users')->assertOk();
    alsRequest($this, 'owner@nile.test', 'GET', '/api/superadmin/users')->assertForbidden();
});

it('refuses the suspended logins and lets the partly suspended ones in', function (string $email, ?string $scope) {
    $this->seed(AccessLevelSeeder::class);

    $response = alsLogin($this, $email);

    $scope === null
        ? $response->assertOk()
        : $response->assertForbidden()->assertJsonPath('body.scope', $scope);
})->with([
    'suspended account owner' => ['owner@delta.test', 'account'],
    'suspended account employee' => ['employee@delta.test', 'account'],
    'only hotel suspended' => ['admin@oasis.test', 'hotel'],
    'default hotel suspended, admin' => ['admin@redsea.test', null],
    'default hotel suspended, employee' => ['employee@redsea.test', null],
    'no hotel at all' => ['unassigned.admin@hospitality.test', null],
]);

it('pairs a device for one admin', function () {
    $this->seed(AccessLevelSeeder::class);

    alsRequest($this, 'admin.single@nile.test', 'GET', '/api/whatsapp-device')->assertJsonPath('body.paired', true);
    alsRequest($this, 'admin.multi@nile.test', 'GET', '/api/whatsapp-device')->assertJsonPath('body.paired', false);
});

it('gives every hotel something to look at', function () {
    $this->seed(AccessLevelSeeder::class);

    expect(Hotel::where('slug', 'like', 'nile-%')->count())->toBe(3)
        ->and(Reservation::whereIn('hotel_id', Hotel::where('slug', 'nile-cairo')->pluck('id'))->count())->toBe(1);
});

it('can be run again and restores what it set', function () {
    $this->seed(AccessLevelSeeder::class);

    $counts = fn () => [User::count(), Hotel::count(), Reservation::withoutGlobalScopes()->count(), WhatsAppDevice::withoutGlobalScopes()->count()];
    $before = $counts();

    User::where('email', 'owner@nile.test')->update(['password' => 'changed']);

    $this->seed(AccessLevelSeeder::class);

    expect($counts())->toBe($before);

    alsLogin($this, 'owner@nile.test')->assertOk();
});

it('refuses to run outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new AccessLevelSeeder)->run())->toThrow(RuntimeException::class, 'well-known password');

    expect(User::where('email', 'owner@nile.test')->exists())->toBeFalse();
});
