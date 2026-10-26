<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Agents\InsightsAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\Admin\ListResult;
use App\Ai\Tools\AuditedTool;
use App\Ai\Tools\Contracts\ReadsData;
use App\Ai\Tools\GetActivitiesTool;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Tests\Support\EntryPointDiscovery;

/*
| Spec 017 (SPEC-103, research R4–R6): the AI tool inventory is the agents'
| real tool lists plus AdminToolset's registry; tests/Inventory/ai_tools.php
| holds only the exceptions. These checks add to AiActivityCoverageTest
| (every write is audited) and AdminToolPermissionTest (every guarded tool
| refuses without its permission):
|
| - every tool class is handed to some agent, or listed as unused;
| - every Admin AI write names the permission its staff endpoint checks;
| - no Admin AI write changes users, staff roles or the hotel itself (D17);
| - every list read is bounded, or listed as bounded by nature.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

function aiToolInventory(): array
{
    return require base_path('tests/Inventory/ai_tools.php');
}

/**
 * Each agent's tools as handed to the model, keyed by agent.
 *
 * @return array{admin: list<GuardedTool>, concierge: list<object>, recommendation: list<object>, insights: list<object>}
 */
function aiToolsByAgent(): array
{
    rapPitchingOn();
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');

    $concierge = GuestConciergeAgent::make(guest: $guest, hotel: $hotel, reservation: $reservation);
    $concierge->pitchTurn = rapTurn($guest, $reservation);
    $admin = cmaAdmin($hotel);

    return [
        'admin' => AdminToolset::for($admin),
        'concierge' => [...$concierge->tools()],
        'recommendation' => [...RecommendationAgent::make(hotel: $hotel, reservation: $reservation)->tools()],
        'insights' => [...(new InsightsAgent($admin))->tools()],
    ];
}

function aiInnerTool(object $tool): object
{
    return $tool instanceof GuardedTool || $tool instanceof AuditedTool ? $tool->inner() : $tool;
}

it('hands every AI tool to some agent, or lists it as unused with a reason', function () {
    $reachable = collect(aiToolsByAgent())->flatten(1)->map(fn ($tool) => aiInnerTool($tool)::class)->unique();
    $unused = aiToolInventory()['unused'];

    foreach (EntryPointDiscovery::aiTools() as $class) {
        if ($reachable->contains($class)) {
            expect(array_key_exists($class, $unused))->toBeFalse("{$class} is listed as unused, but an agent uses it");

            continue;
        }

        expect(trim($unused[$class] ?? ''))->not->toBe('', "{$class} is handed to no agent and not listed as unused");
    }
});

it('names the staff permission for every Admin AI write', function () {
    foreach (aiToolsByAgent()['admin'] as $tool) {
        if ($tool->kind() !== GuardedTool::WRITE) {
            continue;
        }

        expect($tool->permissions() !== [] || $tool->adminOnly())
            ->toBeTrue($tool->name().' is an Admin AI write with no permission behind it');
    }
});

it('never lets the Admin AI change users, staff roles or the hotel itself (D17)', function () {
    foreach (aiToolsByAgent()['admin'] as $tool) {
        expect(array_intersect($tool->writes(), [User::class, StaffRole::class, Hotel::class]))
            ->toBe([], $tool->name().' writes a model D17 keeps read-only to AI');
    }
});

it('bounds every list read, or lists the tool as bounded by nature', function () {
    $bounded = aiToolInventory()['naturally_bounded'];

    $reads = collect(aiToolsByAgent())->flatten(1)
        ->map(fn ($tool) => aiInnerTool($tool))
        ->filter(fn ($tool) => $tool instanceof ReadsData)
        ->unique(fn ($tool) => $tool::class);

    foreach ($reads as $tool) {
        $class = $tool::class;

        if (array_key_exists($class, $bounded)) {
            expect(trim($bounded[$class]))->not->toBe('', "{$class} needs a reason to be bounded by nature");

            continue;
        }

        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        expect(str_contains($source, 'ListResult::'))
            ->toBeTrue("{$class} lists records without ListResult and is not listed as bounded by nature");
    }
});

it('returns at most ListResult::MAX rows and says the list is partial', function () {
    $hotel = cmaHotel();

    foreach (range(1, ListResult::MAX + 10) as $i) {
        rapActivity($hotel, "Activity {$i}");
    }

    $result = json_decode((string) (new GetActivitiesTool($hotel))->handle(new Request([])), true);

    expect($result['returned'])->toBe(ListResult::MAX)
        ->and($result['total'])->toBe(ListResult::MAX + 10)
        ->and($result['partial'])->toBeTrue()
        ->and($result['items'])->toHaveCount(ListResult::MAX);
});
