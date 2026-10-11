<?php

use App\Enums\AiAgentName;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| SPEC-091 FR-015, FR-016: the super-admin cost report breaks each account's
| cost down by AI capability, under the same keys and labels as the usage
| reports.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    config(['ai_cost.fx.usd_per_eur' => 1.25]);
});

function capCostCall(Hotel $hotel, AiAgentName $capability, float $costUsd, bool $estimated = false): AiUsageLog
{
    return AiUsageLog::create([
        'hotel_group_id' => $hotel->hotel_group_id,
        'hotel_id' => $hotel->id,
        'agent' => 'GuestConciergeAgent',
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'operation' => 'chat',
        'input_tokens' => 1000,
        'cached_tokens' => 100,
        'output_tokens' => 50,
        'cost_usd' => $costUsd,
        'cost_is_estimated' => $estimated,
        'trigger_kind' => 'guest_message',
        'capability' => $capability->value,
        'occurred_at' => now(),
    ]);
}

function capCostAccount($test, Hotel $hotel): array
{
    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    return collect($test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($super, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertOk()
        ->json('body.accounts'))
        ->firstWhere('hotel_group_id', $hotel->hotel_group_id);
}

it('breaks an account\'s cost down by capability, adding up to its total', function () {
    [$hotel] = fdHotel(1);

    capCostCall($hotel, AiAgentName::CONCIERGE, 0.400000);
    capCostCall($hotel, AiAgentName::CONCIERGE, 0.100000);
    capCostCall($hotel, AiAgentName::INSIGHTS, 0.025000);
    capCostCall($hotel, AiAgentName::GUEST_MEMORY, 0.012345);

    $account = capCostAccount($this, $hotel);
    $split = $account['by_capability'];

    expect(array_keys($split))->toEqualCanonicalizing(['concierge', 'insights', 'guest_memory'])
        ->and($split['concierge'])->toMatchArray([
            'label' => AiAgentName::CONCIERGE->label(),
            'calls' => 2,
            'input_tokens' => 2200,
            'output_tokens' => 100,
            'ai_cost_usd' => 0.5,
            'cost_is_estimated' => false,
        ])
        ->and($split['guest_memory']['label'])->toBe('Guest memories');

    expect(round(array_sum(array_column($split, 'ai_cost_usd')), 6))->toEqualWithDelta($account['ai_cost_usd'], 0.000001)
        ->and(array_sum(array_column($split, 'calls')))->toBe($account['calls']);
});

it('flags a capability whose cost includes an estimate', function () {
    [$hotel] = fdHotel(1);

    capCostCall($hotel, AiAgentName::DOCUMENT_VISION, 0.0, estimated: true);
    capCostCall($hotel, AiAgentName::DOCUMENT_VISION, 0.2);
    capCostCall($hotel, AiAgentName::CONCIERGE, 0.1);

    $split = capCostAccount($this, $hotel)['by_capability'];

    expect($split['document_vision']['cost_is_estimated'])->toBeTrue()
        ->and($split['concierge']['cost_is_estimated'])->toBeFalse();
});

it('leaves margin empty when no contract value is recorded', function () {
    [$hotel] = fdHotel(1);
    capCostCall($hotel, AiAgentName::CONCIERGE, 0.1);

    $account = capCostAccount($this, $hotel);

    expect($account['gross_margin'])->toBeNull()
        ->and($account['margin_unavailable_reason'])->not->toBeNull();
});

it('keeps the cost report from hotel admins and employees', function () {
    [$hotel] = fdHotel(1);

    foreach ([$hotel->owner, fdEmployee($hotel, Permission::cases())] as $user) {
        $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')
            ->getJson('/api/admin/ai-cost')
            ->assertForbidden();
    }
});
