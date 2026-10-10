<?php

use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\ConfirmsBeforeRunning;
use App\Ai\Tools\Admin\GuardedTool;
use App\Models\Concerns\RecordsEvents;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-055: the invariants of the Admin AI toolset, checked by enumerating
 * the registry rather than trusting each tool (contracts/admin-ai-tools.md).
 * A tool added without a permission, a write that skips the audit trail, or
 * a schema that lets the model reach another hotel fails here.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
});

function aatToolset(): array
{
    [, $admin] = aatHotel();

    return AdminToolset::for($admin);
}

it('gives every tool a permission, admin-only status, or (once) a check of its own', function () {
    $tools = collect(aatToolset());

    foreach ($tools as $tool) {
        expect($tool->permissions() !== [] || $tool->adminOnly() || $tool->selfChecked())
            ->toBeTrue("{$tool->name()} declares no permission");
    }

    expect($tools->filter(fn (GuardedTool $tool) => $tool->selfChecked())->map->name()->values()->all())->toBe(['GetReportTool'])
        ->and($tools->filter(fn (GuardedTool $tool) => $tool->adminOnly())->map->name()->sort()->values()->all())->toBe(['GetHotelSettingsTool', 'GetStaffTool']);
});

it('lets no tool schema name a hotel or an override, and has no delete tool', function () {
    $factory = new JsonSchemaTypeFactory;

    foreach (aatToolset() as $tool) {
        $keys = array_keys($tool->schema($factory));

        expect(collect($keys)->filter(fn (string $key) => preg_match('/override|hotel_id/', $key))->all())->toBe([], $tool->name())
            ->and(str_starts_with($tool->name(), 'Delete'))->toBeFalse();
    }
});

it('names every tool once', function () {
    $names = collect(aatToolset())->map->name();

    expect($names->duplicates()->all())->toBe([]);
});

it('audits every write: each declares the models it changes, and every one of them records events', function () {
    foreach (aatToolset() as $tool) {
        if ($tool->kind() !== GuardedTool::WRITE) {
            expect($tool->writes())->toBe([], $tool->name());

            continue;
        }

        expect($tool->writes())->not->toBe([], "{$tool->name()} declares no models");

        foreach ($tool->writes() as $model) {
            expect(in_array(RecordsEvents::class, class_uses_recursive($model), true))->toBeTrue("{$model} is not audited ({$tool->name()})");
        }
    }
});

it('never lets a write reach users, staff roles or hotel settings', function () {
    foreach (aatToolset() as $tool) {
        expect(array_intersect($tool->writes(), [User::class, StaffRole::class, Hotel::class]))->toBe([], $tool->name());
    }
});

it('asks for confirmation exactly for the hard-to-reverse actions', function () {
    $s = aatSeed();
    $byName = collect(AdminToolset::for($s['admin']))->keyBy(fn (GuardedTool $tool) => $tool->name());

    $confirms = fn (string $name, array $args) => $byName[$name]->shouldRequestApproval(new Request($args)) !== null;

    expect($confirms('CancelReservationTool', aatArgs('CancelReservationTool', $s)))->toBeTrue()
        ->and($confirms('CheckOutTool', aatArgs('CheckOutTool', $s)))->toBeTrue()
        ->and($confirms('SetRoomOutOfOrderTool', aatArgs('SetRoomOutOfOrderTool', $s)))->toBeTrue()
        ->and($confirms('UpdateBookingStatusTool', ['booking' => $s['booking']->reference, 'status' => 'cancelled', 'reason' => 'x']))->toBeTrue()
        ->and($confirms('UpdateBookingStatusTool', ['booking' => $s['booking']->reference, 'status' => 'confirmed']))->toBeFalse()
        ->and($confirms('DecideBookingCancellationTool', ['booking' => $s['booking']->reference, 'decision' => 'approve']))->toBeTrue()
        ->and($confirms('DecideBookingCancellationTool', ['booking' => $s['booking']->reference, 'decision' => 'decline', 'note' => 'n']))->toBeFalse();

    $gated = $byName->filter(fn (GuardedTool $tool) => $tool->inner() instanceof ConfirmsBeforeRunning)->keys()->sort()->values()->all();

    expect($gated)->toBe(['CancelReservationTool', 'CheckOutTool', 'DecideBookingCancellationTool', 'DecideRecommendationTool', 'SetRoomOutOfOrderTool', 'UpdateBookingStatusTool', 'UpdateReservationTool']);

    foreach ($byName->except($gated) as $name => $tool) {
        expect($tool->shouldRequestApproval(new Request(aatArgs($name, $s))))->toBeNull($name);
    }
});

it('changes no user, staff role or hotel whichever write tool runs (SC-006)', function () {
    Queue::fake();
    $s = aatSeed();
    $snapshot = fn () => [
        DB::table('users')->count(), DB::table('users')->max('updated_at'),
        DB::table('staff_roles')->count(), DB::table('staff_roles')->max('updated_at'),
        DB::table('hotels')->count(), DB::table('hotels')->max('updated_at'),
    ];
    $before = $snapshot();
    $this->travel(5)->minutes();

    foreach (AdminToolset::for($s['admin']) as $tool) {
        if ($tool->kind() === GuardedTool::WRITE) {
            aatCall($tool, aatArgs($tool->name(), $s));
        }
    }

    expect($snapshot())->toBe($before);
});
