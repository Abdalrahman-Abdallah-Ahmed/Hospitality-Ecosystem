<?php

use App\Models\Hotel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/*
| Spec 017 (SPEC-102, research R2): every tenant route, driven by one generic
| rule per shape, proves a Hotel A admin gets nothing of Hotel B's.
|
| - index: a GET with no parameters never shows a Hotel B id, anywhere in
|   the body (nested records included).
| - bound: a route whose parameters bind to Hotel B's records answers
|   404 (the record is not found under Hotel A's scope) and leaves those
|   records unchanged.
| - foreign_key: a create that names Hotel B's records by *_id is refused.
|   This is a smoke check: a 422 may come from other missing fields, so the
|   per-feature tests in TenantIsolationTest stay the precise proof.
|
| Routes none of the rules can drive are listed, with the test that covers
| them, in tests/Inventory/entry_points.php (EntryPointInventoryTest).
|
| Spec 030: this part checks the routes of the Reservations slice of
| crossTenantSlices() (tests/Pest.php); the other slices have their own parts.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    // The ledger is off by default (spec 019); on here so its routes are
    // actually looked up and the isolation rules test them.
    config(['features.transactions' => true]);
});

it('never shows another hotel\'s rows on a list or report route', function (string $method, string $uri) {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $idsB = [];

    foreach (tenantOwnedModelFactories() as $factory) {
        $factory($hotelA);
        $idB = (string) $factory($hotelB)->getKey();

        // Integer keys ("2") match anywhere in a body; only UUIDs are evidence.
        if (Str::isUuid($idB)) {
            $idsB[] = $idB;
        }
    }

    $response = $this->actingAs(crossTenantAdmin($hotelA))
        ->withHeaders(apiHeaders())
        ->json($method, '/'.$uri);

    expect($response->getStatusCode())->toBeLessThan(500);

    $body = (string) $response->getContent();

    foreach ($idsB as $id) {
        expect(str_contains($body, $id))->toBeFalse("{$method} /{$uri} returned Hotel B id {$id}");
    }
})->with(fn () => crossTenantRoutesFor('index', 'Reservations'));

it('never finds or changes another hotel\'s record through a route parameter', function (string $method, string $uri) {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $factories = tenantOwnedModelFactories();
    $path = '/'.$uri;
    $records = [];

    foreach (crossTenantBoundModels(crossTenantRoute($method, $uri)) as $name => $class) {
        $record = $factories[$class]($hotelB);
        $records[] = $record;
        $path = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $record->getKey(), $path);
    }

    $before = collect($records)->map(fn ($record) => $record->newQueryWithoutScopes()->find($record->getKey())?->getAttributes());

    $response = $this->actingAs(crossTenantAdmin($hotelA))
        ->withHeaders(apiHeaders())
        ->json($method, $path);

    expect($response->getStatusCode())->toBe(404, "{$method} {$path} answered {$response->getStatusCode()}");

    foreach ($records as $index => $record) {
        expect($record->newQueryWithoutScopes()->find($record->getKey())?->getAttributes())
            ->toBe($before[$index], "{$method} {$path} changed Hotel B's ".class_basename($record));
    }
})->with(fn () => crossTenantRoutesFor('bound', 'Reservations'));

/*
| Spec 025 (SC-009): an admin working in one hotel of an account, driven over
| every bound tenant route. Hotel B's own admin gives the expected answer, so a
| route that refuses for reasons of its own (a record's state, a disabled
| feature) isn't mistaken for a hotel problem.
*/
it('gives an account owner who chooses a hotel the same answer as that hotel\'s own admin', function (string $method, string $uri) {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $hotelA->update(['hotel_group_id' => $hotelB->hotel_group_id]);
    $owner = crossTenantAdmin($hotelA);
    $owner->update(['hotel_group_id' => $hotelB->hotel_group_id, 'group_role' => 'owner']);
    $factories = tenantOwnedModelFactories();
    $path = '/'.$uri;

    foreach (crossTenantBoundModels(crossTenantRoute($method, $uri)) as $name => $class) {
        $path = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $factories[$class]($hotelB)->getKey(), $path);
    }

    $expected = spRequest($this, crossTenantAdmin($hotelB), $method, $path)->getStatusCode();

    expect(spRequest($this, $owner, $method, $path, headers: ['X-Hotel-Id' => $hotelB->id])->getStatusCode())
        ->toBe($expected, "{$method} {$path}")
        ->and(spRequest($this, $owner, $method, $path)->getStatusCode())
        ->toBe(404, "{$method} {$path} with no hotel chosen");
})->with(fn () => array_filter(crossTenantRoutesFor('bound', 'Reservations'), fn (array $route) => $route[0] === 'GET'));

it('keeps an admin out of a hotel of their account they are not assigned to', function (string $method, string $uri) {
    $home = makeHotel();
    $unassigned = makeHotel();
    $unassigned->update(['hotel_group_id' => $home->hotel_group_id]);
    $factories = tenantOwnedModelFactories();
    $path = '/'.$uri;

    foreach (crossTenantBoundModels(crossTenantRoute($method, $uri)) as $name => $class) {
        $path = str_replace(['{'.$name.'}', '{'.$name.'?}'], (string) $factories[$class]($unassigned)->getKey(), $path);
    }

    $admin = crossTenantAdmin($home);

    expect(spRequest($this, $admin, $method, $path)->getStatusCode())->toBe(404, "{$method} {$path}")
        ->and(spRequest($this, $admin, $method, $path, headers: ['X-Hotel-Id' => $unassigned->id])->getStatusCode())->toBe(403, "{$method} {$path} choosing it");
})->with(fn () => crossTenantRoutesFor('bound', 'Reservations'));

it('refuses a create that points at another hotel\'s records', function (string $method, string $uri) {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $factories = tenantOwnedModelFactories();
    $payload = [];

    foreach (crossTenantForeignKeys(crossTenantRoute($method, $uri)) as $field => $class) {
        $payload[$field] = (string) $factories[$class]($hotelB)->getKey();
    }

    $response = $this->actingAs(crossTenantAdmin($hotelA))
        ->withHeaders(apiHeaders())
        ->json($method, '/'.$uri, $payload);

    expect($response->getStatusCode())->toBeIn([403, 404, 422], "{$method} /{$uri} accepted Hotel B ids");
})->with(fn () => crossTenantRoutesFor('foreign_key', 'Reservations'));
