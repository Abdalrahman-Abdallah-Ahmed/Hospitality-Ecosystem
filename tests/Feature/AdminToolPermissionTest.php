<?php

use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-055 FR-002, SC-002: every Admin AI tool refuses, changing nothing,
 * for a user without the permission its staff endpoint checks, and is
 * allowed for a user with exactly that permission. The tools are taken from
 * the registry, so a new tool is covered without editing this file.
 *
 * The advisor endpoint itself stays admin-only (FR-004); these tests build
 * the guarded tools directly for employees with narrower rights.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
    knFakeEmbeddings();
});

/**
 * Every tool the guard checks a permission for (not the admin-only ones,
 * not the report tool, which checks per report).
 *
 * @return list<string>
 */
function aatPermissionToolNames(): array
{
    [, $admin] = aatHotel();

    return collect(AdminToolset::for($admin))
        ->reject(fn (GuardedTool $tool) => $tool->adminOnly() || $tool->selfChecked())
        ->map->name()
        ->values()
        ->all();
}

it('refuses every tool, changing nothing, for an employee without its permission', function () {
    $s = aatSeed();
    $employee = aatEmployee($s['hotel'], []);
    $before = aatFingerprint($s['hotel']);
    $this->travel(5)->minutes();

    foreach (aatPermissionToolNames() as $name) {
        $result = aatCall(aatTool($employee, $name), aatArgs($name, $s));

        expect($result)->toBeString($name)
            ->and($result)->toContain('You do not have permission', $name);
    }

    expect(aatFingerprint($s['hotel']))->toBe($before);
});

it('allows every tool for an employee holding exactly its permission', function () {
    foreach (aatPermissionToolNames() as $name) {
        $s = aatSeed();
        $probe = aatTool($s['admin'], $name);
        $employee = aatEmployee($s['hotel'], $probe->permissions());

        $result = aatCall(aatTool($employee, $name), aatArgs($name, $s));

        expect(is_string($result) ? $result : json_encode($result))->not->toContain('You do not have permission', $name);
    }
});

it('keeps users, roles and settings to admins, even for an employee holding every permission', function (string $name) {
    $s = aatSeed();
    $everything = aatEmployee($s['hotel'], Permission::cases());

    expect(aatCall(aatTool($everything, $name), []))->toContain('You do not have permission')
        ->and(aatCall(aatTool($s['admin'], $name), []))->toBeArray();
})->with(['GetStaffTool', 'GetHotelSettingsTool']);

it('applies a permission taken away mid-conversation to the very next call', function () {
    $s = aatSeed();
    $employee = aatEmployee($s['hotel'], [Permission::ROOMS_VIEW]);
    $tool = aatTool($employee, 'GetRoomsTool');

    expect(aatCall($tool, []))->toBeArray();

    $employee->staffRole->update(['permissions' => []]);

    expect(aatCall($tool, []))->toContain('You do not have permission');
});

it('checks each report against the permission of its own screen', function (string $report, Permission $permission) {
    $s = aatSeed();

    expect(aatCall(aatTool(aatEmployee($s['hotel'], []), 'GetReportTool'), ['report' => $report]))
        ->toContain('You do not have permission');

    expect(aatCall(aatTool(aatEmployee($s['hotel'], [$permission]), 'GetReportTool'), ['report' => $report]))
        ->toBeArray();
})->with([
    'dashboard' => ['dashboard', Permission::DASHBOARD_VIEW],
    'occupancy' => ['occupancy', Permission::DASHBOARD_VIEW],
    'conversion' => ['conversion', Permission::RECOMMENDATIONS_VIEW],
    'insights' => ['insights', Permission::AI_INSIGHTS_VIEW],
]);

it('keeps the usage report to admins, even for an employee holding every permission', function () {
    $s = aatSeed();

    expect(aatCall(aatTool(aatEmployee($s['hotel'], Permission::cases()), 'GetReportTool'), ['report' => 'usage']))
        ->toContain('You do not have permission');
});
