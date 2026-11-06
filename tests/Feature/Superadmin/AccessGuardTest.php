<?php

use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Spec 023 Story 7: every new endpoint refuses anyone but a super admin.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

dataset('administration routes', [
    'list accounts' => ['GET', '/accounts'],
    'create account' => ['POST', '/accounts'],
    'view account' => ['GET', '/accounts/{account}'],
    'edit account' => ['PUT', '/accounts/{account}'],
    'delete account' => ['DELETE', '/accounts/{account}'],
    'create hotel' => ['POST', '/accounts/{account}/hotels'],
    'list hotels' => ['GET', '/hotels'],
    'view hotel' => ['GET', '/hotels/{hotel}'],
    'edit hotel' => ['PUT', '/hotels/{hotel}'],
    'delete hotel' => ['DELETE', '/hotels/{hotel}'],
    'list users' => ['GET', '/users'],
    'create user' => ['POST', '/users'],
    'view user' => ['GET', '/users/{user}'],
    'edit user' => ['PUT', '/users/{user}'],
    'change access' => ['PUT', '/users/{user}/access'],
    'send reset' => ['POST', '/users/{user}/password-reset'],
    'revoke tokens' => ['POST', '/users/{user}/revoke-tokens'],
]);

/**
 * @return array{0: string, 1: Hotel, 2: User}
 */
function guardedTarget(string $path): array
{
    $hotel = makeHotel();
    $target = User::factory()->create(['hotel_id' => $hotel->id, 'name' => 'Target']);

    $url = '/api/superadmin'.str_replace(
        ['{account}', '{hotel}', '{user}'],
        [$hotel->hotel_group_id, $hotel->id, $target->id],
        $path,
    );

    return [$url, $hotel, $target];
}

function assertNothingChanged(Hotel $hotel, User $target): void
{
    expect(HotelGroup::whereKey($hotel->hotel_group_id)->exists())->toBeTrue()
        ->and($hotel->fresh())->not->toBeNull()
        ->and($target->fresh()->name)->toBe('Target')
        ->and(EventLog::withoutGlobalScopes()->where('event_type', 'like', 'platform.%')->count())->toBe(0);
}

it('refuses a hotel admin', function (string $method, string $path) {
    [$url, $hotel, $target] = guardedTarget($path);

    $this->withHeaders(apiHeaders())->actingAs($hotel->owner, 'sanctum')
        ->json($method, $url, ['name' => 'Changed', 'slug' => 'changed'])
        ->assertForbidden();

    assertNothingChanged($hotel, $target);
})->with('administration routes');

it('refuses an employee', function (string $method, string $path) {
    [$url, $hotel, $target] = guardedTarget($path);
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);

    $this->withHeaders(apiHeaders())->actingAs($employee, 'sanctum')
        ->json($method, $url, ['name' => 'Changed', 'slug' => 'changed'])
        ->assertForbidden();

    assertNothingChanged($hotel, $target);
})->with('administration routes');

it('refuses a request with no login token', function (string $method, string $path) {
    [$url, $hotel, $target] = guardedTarget($path);

    $this->withHeaders(apiHeaders())->json($method, $url, ['name' => 'Changed'])->assertUnauthorized();

    assertNothingChanged($hotel, $target);
})->with('administration routes');

it('refuses a request with no API key', function (string $method, string $path) {
    [$url, $hotel, $target] = guardedTarget($path);

    $this->actingAs(User::factory()->role(UserRole::SUPER_ADMIN)->create(), 'sanctum')
        ->json($method, $url, ['name' => 'Changed'])
        ->assertUnauthorized();

    assertNothingChanged($hotel, $target);
})->with('administration routes');
