<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EntryPointDiscovery;

/*
| Spec 017 (FR-018, SC-006, research R6): no list endpoint returns every row
| on request. Each GET list route refuses an oversized page and answers with
| pagination meta, or is listed in tests/Inventory/entry_points.php under
| route_exceptions.bounded_list with the reason it is bounded anyway.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('pages every list route and refuses an oversized page', function () {
    $exceptions = (require base_path('tests/Inventory/entry_points.php'))['route_exceptions']['bounded_list'];
    $hotel = makeHotel();
    $admin = User::findOrFail($hotel->owner_id);
    $checked = 0;

    foreach (EntryPointDiscovery::tenantRoutes() as $key => $route) {
        [$method] = explode(' ', $key, 2);

        if (crossTenantRuleFor($route, $method) !== 'index' || array_key_exists($key, $exceptions)) {
            continue;
        }

        $checked++;
        $oversized = $this->actingAs($admin)->withHeaders(apiHeaders())->getJson('/'.$route->uri().'?per_page=10000');

        expect($oversized->status())->toBe(422, "{$key} accepts per_page=10000");

        $default = $this->actingAs($admin)->withHeaders(apiHeaders())->getJson('/'.$route->uri());

        // Routes that need parameters (or another role) answer 4xx here;
        // the page-size rule above is what matters for them.
        if ($default->status() === 200) {
            expect($default->json('body.meta'))->not->toBeNull("{$key} answers a list without pagination meta");
        }
    }

    expect($checked)->toBeGreaterThan(20);
});

it('lists no exception that is not a GET tenant route', function () {
    $routes = EntryPointDiscovery::tenantRoutes();

    foreach ((require base_path('tests/Inventory/entry_points.php'))['route_exceptions']['bounded_list'] as $key => $reason) {
        expect($routes->has($key))->toBeTrue("{$key} is not a tenant route")
            ->and(trim($reason))->not->toBe('', "{$key} needs a reason");
    }
});
