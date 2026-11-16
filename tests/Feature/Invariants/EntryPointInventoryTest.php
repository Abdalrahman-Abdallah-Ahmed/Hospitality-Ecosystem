<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Support\EntryPointDiscovery;

/*
| Spec 017 (SPEC-102, research R1): every way into tenant data is either
| driven by a generic cross-tenant check or listed in
| tests/Inventory/entry_points.php with the test that covers it. A new route,
| job or scheduled task that is neither fails here, by name.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function entryPointInventory(): array
{
    return require base_path('tests/Inventory/entry_points.php');
}

/**
 * Every it('…') description in the test suite, as the runner reads it.
 *
 * @return list<string>
 */
function testDescriptions(): array
{
    static $descriptions = null;

    if ($descriptions === null) {
        $descriptions = [];

        foreach (Finder::create()->files()->in(base_path('tests/Feature'))->name('*Test.php') as $file) {
            preg_match_all("/^\s*it\('((?:[^'\\\\]|\\\\.)*)'/m", $file->getContents(), $matches);

            foreach ($matches[1] as $raw) {
                $descriptions[] = stripslashes($raw);
            }
        }
    }

    return $descriptions;
}

/**
 * @param  array{covered_by?: string, reason?: string}  $entry
 */
function assertCoveredOrReasoned(string $name, array $entry): void
{
    if (isset($entry['covered_by'])) {
        expect(in_array($entry['covered_by'], testDescriptions(), true))
            ->toBeTrue("{$name} is covered by \"{$entry['covered_by']}\", but no test has that description");

        return;
    }

    expect(trim($entry['reason'] ?? ''))->not->toBe('', "{$name} needs a covered_by test or a reason");
}

it('covers every tenant route by a generic check or the inventory', function () {
    $listed = entryPointInventory()['routes'];
    $uncovered = [];

    foreach (EntryPointDiscovery::tenantRoutes() as $key => $route) {
        [$method] = explode(' ', $key, 2);

        if (crossTenantRuleFor($route, $method) !== null) {
            continue;
        }

        isset($listed[$key]) ? assertCoveredOrReasoned($key, $listed[$key]) : $uncovered[] = $key;
    }

    expect($uncovered)->toBe([], 'Routes with no isolation check: '.implode(', ', $uncovered));
});

it('lists no route that no longer exists or that a generic check already drives', function () {
    $routes = EntryPointDiscovery::tenantRoutes();

    foreach (array_keys(entryPointInventory()['routes']) as $key) {
        expect($routes->has($key))->toBeTrue("Inventory lists {$key}, which is not a tenant route");

        [$method] = explode(' ', $key, 2);
        expect(crossTenantRuleFor($routes->get($key), $method))
            ->toBeNull("{$key} is driven by CrossTenantRouteTest; remove it from the inventory");
    }
});

it('classifies every queued job, with a reason for any that is not hotel-scoped', function () {
    $listed = entryPointInventory()['jobs'];
    $jobs = EntryPointDiscovery::queuedJobs();

    expect(array_values(array_diff($jobs, array_keys($listed))))->toBe([], 'Jobs missing from the inventory')
        ->and(array_values(array_diff(array_keys($listed), $jobs)))->toBe([], 'Inventory lists jobs that no longer exist');

    foreach ($listed as $class => $entry) {
        expect($entry['scope'])->toBeIn(['hotel', 'cross_hotel', 'none'], "{$class} has an unknown scope");

        if ($entry['scope'] !== 'hotel') {
            expect(trim($entry['reason'] ?? ''))->not->toBe('', "{$class} crosses or skips hotels without a reason");
        }
    }
});

it('lists every scheduled task, each a known job or command', function () {
    $listed = entryPointInventory()['scheduled'];
    $scheduled = EntryPointDiscovery::scheduledEvents();

    expect(array_values(array_diff($scheduled, $listed)))->toBe([], 'Scheduled tasks missing from the inventory')
        ->and(array_values(array_diff($listed, $scheduled)))->toBe([], 'Inventory lists scheduled tasks that no longer run');

    foreach ($listed as $name) {
        if (class_exists($name)) {
            expect(array_key_exists($name, entryPointInventory()['jobs']))->toBeTrue("Scheduled {$name} is not classified under jobs");
        }
    }
});

it('justifies every intended global record', function () {
    foreach (entryPointInventory()['global_data'] as $class => $entry) {
        expect(class_exists($class))->toBeTrue("{$class} does not exist");
        assertCoveredOrReasoned($class, $entry);
    }
});
