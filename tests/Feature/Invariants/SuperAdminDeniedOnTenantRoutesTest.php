<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Middleware\DenySuperAdmin;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/*
| Spec 028 (Stage 6): a super admin's own token is refused on every hotel route,
| before any record is looked up. They work in /api/superadmin, and reach a
| hotel only by impersonating one of its users.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/** The two routes a super admin's own token still reaches. */
function saExempt(): array
{
    return ['POST api/logout', 'GET api/user'];
}

/**
 * Every authenticated hotel route and method, by "METHOD uri". The super admin
 * routes are authenticated too, and are left out by their own guard.
 *
 * @return array<string, RoutingRoute>
 */
function saTenantRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (! str_starts_with($route->uri(), 'api/')
            || ! in_array('auth:sanctum', $middleware, true)
            || in_array('super_admin', $middleware, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.$route->uri()] = $route;
        }
    }

    return $routes;
}

function saRuns(RoutingRoute $route): bool
{
    return in_array('no_super_admin', $route->gatherMiddleware(), true)
        && ! in_array('no_super_admin', $route->excludedMiddleware(), true);
}

it('refuses super admins on every authenticated hotel route but logout and /user', function () {
    $missing = [];

    foreach (saTenantRoutes() as $name => $route) {
        if (in_array($name, saExempt(), true)) {
            expect(saRuns($route))->toBeFalse("{$name} is exempt, but runs no_super_admin");

            continue;
        }

        if (! saRuns($route)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([])
        ->and(count(saTenantRoutes()))->toBeGreaterThan(100);
});

it('refuses before a record in the URL is looked up', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    expect(array_search(DenySuperAdmin::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true))
        ->toBeGreaterThan(array_search(EnsureNotImpersonating::class, $priority, true));
});

it('answers 403 to a super admin on every hotel route, for records that do not exist', function () {
    config(['features.transactions' => true]);
    // Throttling isn't what this checks, and one user calls every route here.
    $this->withoutMiddleware(ThrottleRequests::class);

    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    $answered = [];

    foreach (saTenantRoutes() as $name => $route) {
        if (in_array($name, saExempt(), true)) {
            continue;
        }

        [$method] = explode(' ', $name, 2);
        // No body: the refusal comes before validation.
        $uri = '/'.preg_replace_callback('/\{[^}]+\}/', fn () => (string) Str::uuid(), $route->uri());
        $response = $this->actingAs($superAdmin, 'sanctum')->json($method, $uri, [], apiHeaders());

        if ($response->status() !== 403 || $response->json('message') !== DenySuperAdmin::REFUSAL) {
            $answered[] = "{$name} → {$response->status()}";
        }
    }

    expect($answered)->toBe([]);
});

// User Story 2: a super admin still signs in, reads who they are, and signs out.

it('still signs a super admin in, tells them who they are, and signs them out', function () {
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create(['password' => Hash::make('secret-password')]);
    $everyPermission = array_map(fn (Permission $permission) => $permission->value, Permission::cases());

    $login = $this->withHeaders(apiHeaders())
        ->postJson('/api/login', ['email' => $superAdmin->email, 'password' => 'secret-password'])
        ->assertOk()
        ->assertJsonPath('body.user.role', UserRole::SUPER_ADMIN->value);
    expect($login->json('body.user.permissions'))->toBe($everyPermission);

    $bearer = [...apiHeaders(), 'Authorization' => 'Bearer '.$login->json('body.token')];
    // Login checks the password through the session guard; drop it, so the
    // requests below authenticate with the token, as a client's would.
    app('auth')->guard('web')->logout();
    app('auth')->forgetGuards();

    $user = $this->getJson('/api/user', $bearer)->assertOk()->assertJsonPath('body.role', UserRole::SUPER_ADMIN->value);
    expect($user->json('body.permissions'))->toBe($everyPermission)
        ->and($user->json('body'))->not->toHaveKey('impersonation');

    app('auth')->forgetGuards();
    $this->postJson('/api/logout', [], $bearer)->assertOk();

    expect(PersonalAccessToken::where('tokenable_id', $superAdmin->id)->count())->toBe(0);
});

// User Story 3: impersonation is the way into a hotel.

it('lets an impersonation session through, with the hotel user\'s own view and permissions', function () {
    $hotel = makeHotel();
    $other = makeHotel();
    $mine = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Mine', 'channel' => 'booking_com', 'external_id' => 'ext-1']);
    Guest::create(['hotel_id' => $other->id, 'first_name' => 'Theirs', 'channel' => 'booking_com', 'external_id' => 'ext-2']);
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();
    // Made before any session: the test process keeps the last request's user,
    // and a user can't be written during a session.
    $employee = fdEmployee($hotel, [Permission::TASKS_VIEW]);

    $token = impersonationTokenFor($this, $superAdmin, adminOfHotel($hotel));
    app('auth')->forgetGuards();
    $guests = $this->getJson('/api/guest', [...apiHeaders(), 'Authorization' => 'Bearer '.$token])->assertOk();

    expect(collect($guests->json('body.data'))->pluck('id')->all())->toBe([$mine->id]);

    // The user's own permissions decide, with no super admin bypass behind them.
    $token = impersonationTokenFor($this, $superAdmin, $employee);
    app('auth')->forgetGuards();

    $this->getJson('/api/room', [...apiHeaders(), 'Authorization' => 'Bearer '.$token])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});
