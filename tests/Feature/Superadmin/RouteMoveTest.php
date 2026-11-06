<?php

use App\Enums\UserRole;
use App\Models\GuestMemoryExtraction;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * Spec 022: the super admin area moved from /api/admin to /api/superadmin as a
 * hard cut. The dataset is the 25 endpoints that moved, not the whole area:
 * later stages add routes here, and the middleware check below must cover
 * those too.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

dataset('moved super admin endpoints', [
    ['GET', 'usage'],
    ['GET', 'ai-cost'],
    ['GET', 'knowledge-documents/deleted'],
    ['GET', 'knowledge-documents'],
    ['POST', 'knowledge-documents'],
    ['GET', 'knowledge-documents/{id}'],
    ['PUT', 'knowledge-documents/{id}'],
    ['DELETE', 'knowledge-documents/{id}'],
    ['POST', 'knowledge-documents/{id}/file'],
    ['GET', 'knowledge-documents/{id}/download'],
    ['GET', 'knowledge-documents/{id}/text'],
    ['PUT', 'knowledge-documents/{id}/text'],
    ['DELETE', 'knowledge-documents/{id}/text'],
    ['POST', 'knowledge-documents/{id}/reindex'],
    ['POST', 'knowledge-documents/{id}/restore'],
    ['GET', 'knowledge-base-articles'],
    ['POST', 'knowledge-base-articles'],
    ['GET', 'knowledge-base-articles/{id}'],
    ['PUT', 'knowledge-base-articles/{id}'],
    ['DELETE', 'knowledge-base-articles/{id}'],
    ['POST', 'knowledge/rebuilds'],
    ['GET', 'knowledge/rebuilds'],
    ['GET', 'knowledge/rebuilds/{id}'],
    ['GET', 'guest-memory-extractions'],
    ['POST', 'guest-memory-extractions/{extraction}/rerun'],
]);

function routeMoveSuperAdmin(): User
{
    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

/**
 * The address to call. {extraction} is bound to a model, so the route needs a
 * real record; {id} is a plain parameter, so any id reaches the guard.
 */
function routeMovePath(string $path, Hotel $hotel): string
{
    if (str_contains($path, '{extraction}')) {
        [$guest, $reservation] = rapInHouseStay($hotel);

        $extraction = GuestMemoryExtraction::create([
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => $reservation->id,
            'status' => 'failed',
            'attempts' => 2,
        ]);

        $path = str_replace('{extraction}', $extraction->id, $path);
    }

    return str_replace('{id}', (string) Str::uuid(), $path);
}

it('serves each moved endpoint under the new prefix', function (string $method, string $path) {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === "api/superadmin/{$path}" && in_array($method, $route->methods(), true));

    expect($route)->not->toBeNull("{$method} api/superadmin/{$path} is missing");
})->with('moved super admin endpoints');

it('reads "deleted" as the deleted documents list, not as a document id', function () {
    $response = cmaRequest($this, routeMoveSuperAdmin(), 'GET', '/api/superadmin/knowledge-documents/deleted')->assertOk();

    expect($response->json('body.data'))->toBeArray();
});

it('puts every route in the area behind the full middleware stack', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/superadmin/'));

    expect($routes->count())->toBeGreaterThanOrEqual(25);

    $routes->each(fn ($route) => expect($route->gatherMiddleware())
        ->toBe(['api', 'api.key', 'auth:sanctum', 'throttle:api', 'tenant', 'super_admin'], "{$route->uri()} has the wrong middleware"));
});

it('refuses hotel admins and employees on every moved endpoint', function (string $method, string $path) {
    $hotel = cmaHotel();
    $uri = '/api/superadmin/'.routeMovePath($path, $hotel);

    cmaRequest($this, cmaAdmin($hotel), $method, $uri)->assertForbidden();
    cmaRequest($this, cmaEmployee($hotel), $method, $uri)->assertForbidden();
})->with('moved super admin endpoints');

it('refuses a request without a login token on every moved endpoint', function (string $method, string $path) {
    $uri = '/api/superadmin/'.routeMovePath($path, cmaHotel());

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->json($method, $uri)->assertUnauthorized();
})->with('moved super admin endpoints');

it('refuses a request without an API key on every moved endpoint', function (string $method, string $path) {
    $uri = '/api/superadmin/'.routeMovePath($path, cmaHotel());

    $this->json($method, $uri)->assertUnauthorized();
})->with('moved super admin endpoints');

it('answers 404 on every old address, for a super admin', function (string $method, string $path) {
    $uri = '/api/admin/'.str_replace(['{id}', '{extraction}'], (string) Str::uuid(), $path);

    cmaRequest($this, routeMoveSuperAdmin(), $method, $uri)->assertNotFound();
})->with('moved super admin endpoints');

it('answers 404 on every old address, with no credentials', function (string $method, string $path) {
    $uri = '/api/admin/'.str_replace(['{id}', '{extraction}'], (string) Str::uuid(), $path);

    $this->json($method, $uri)->assertNotFound();
})->with('moved super admin endpoints');

it('registers nothing under the old prefix', function () {
    $old = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => $uri === 'api/admin' || str_starts_with($uri, 'api/admin/'));

    expect($old->all())->toBe([]);
});

it('has no endpoint at the bare prefix', function () {
    cmaRequest($this, routeMoveSuperAdmin(), 'GET', '/api/superadmin')->assertNotFound();
});
