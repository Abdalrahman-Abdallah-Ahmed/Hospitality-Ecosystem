<?php

use App\Enums\AiAgentName;
use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function aiBlockHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function aiBlockHotel(string $name, ?string $groupId = null): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => 'ai-block-'.uniqid(),
        'currency' => 'EUR',
        'hotel_group_id' => $groupId,
    ]);
}

function aiBlockCall(Hotel $hotel, AiAgentName $capability, int $input, int $output, int $cached = 0): AiUsageLog
{
    return AiUsageLog::create([
        'hotel_group_id' => $hotel->hotel_group_id,
        'hotel_id' => $hotel->id,
        'agent' => 'GuestConciergeAgent',
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'operation' => 'chat',
        'input_tokens' => $input,
        'output_tokens' => $output,
        'cached_tokens' => $cached,
        'cost_usd' => 1.25,
        'trigger_kind' => 'guest_message',
        'capability' => $capability->value,
        'occurred_at' => now(),
    ]);
}

function aiBlockAdmin(Hotel $hotel): User
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $admin->update(['hotel_id' => $hotel->id]);

    return $admin->fresh();
}

it('reports requests and tokens per capability and per hotel, adding up to the totals', function () {
    $main = aiBlockHotel('Main');
    $annex = aiBlockHotel('Annex', $main->hotel_group_id);
    $other = aiBlockHotel('Elsewhere');

    aiBlockCall($main, AiAgentName::CONCIERGE, 1000, 100, cached: 500);
    aiBlockCall($main, AiAgentName::CONCIERGE, 2000, 200);
    aiBlockCall($annex, AiAgentName::INSIGHTS, 300, 30);
    aiBlockCall($annex, AiAgentName::GUEST_MEMORY, 400, 40);
    aiBlockCall($other, AiAgentName::CONCIERGE, 99999, 9999);

    $ai = $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($main), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.ai');

    // Cached input is input to the hotel; it only differs in price.
    expect($ai['requests'])->toBe(4)
        ->and($ai['input_tokens'])->toBe(4200)
        ->and($ai['output_tokens'])->toBe(370)
        ->and($ai['total_tokens'])->toBe(4570)
        ->and($ai['by_capability']['concierge'])->toMatchArray(['label' => 'Concierge', 'requests' => 2, 'input_tokens' => 3500, 'output_tokens' => 300])
        ->and($ai['by_hotel'][$annex->id])->toMatchArray(['hotel' => 'Annex', 'requests' => 2, 'total_tokens' => 770]);

    // Every reportable capability is listed, at zero when idle; the
    // classifier and the unattributed gap are not.
    expect(array_keys($ai['by_capability']))->toEqualCanonicalizing(array_map(fn (AiAgentName $a) => $a->value, AiAgentName::reportable()))
        ->and($ai['by_capability']['document_vision']['requests'])->toBe(0)
        ->and($ai['by_capability'])->not->toHaveKey('turn_signal')
        ->and($ai['by_capability'])->not->toHaveKey('unattributed');

    foreach (['by_capability', 'by_hotel'] as $breakdown) {
        foreach (['requests', 'input_tokens', 'output_tokens', 'total_tokens'] as $figure) {
            expect(array_sum(array_column($ai[$breakdown], $figure)))->toBe($ai[$figure], "{$breakdown}.{$figure} must add up");
        }
    }
});

it('shows unattributed calls only when there are some, so the breakdown still adds up', function () {
    $hotel = aiBlockHotel('Gap Hotel');

    aiBlockCall($hotel, AiAgentName::CONCIERGE, 100, 10);
    aiBlockCall($hotel, AiAgentName::UNATTRIBUTED, 50, 5);

    $ai = $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($hotel), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.ai');

    expect($ai['by_capability']['unattributed'])->toMatchArray(['label' => 'Unattributed', 'requests' => 1])
        ->and(array_sum(array_column($ai['by_capability'], 'requests')))->toBe($ai['requests']);
});

it('never shows one account another account\'s AI usage', function () {
    $ours = aiBlockHotel('Ours');
    $theirs = aiBlockHotel('Theirs');

    aiBlockCall($ours, AiAgentName::CONCIERGE, 10, 1);
    aiBlockCall($theirs, AiAgentName::CONCIERGE, 5000, 500);

    $ai = $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($ours), 'sanctum')
        ->getJson('/api/usage?hotel_group_id='.$theirs->hotel_group_id)
        ->assertOk()
        ->json('body.ai');

    expect($ai['requests'])->toBe(1)
        ->and($ai['by_hotel'])->toHaveKey($ours->id)
        ->and($ai['by_hotel'])->not->toHaveKey($theirs->id);
});

it('names no model or provider in the hotel-facing AI usage', function () {
    $hotel = aiBlockHotel('Quiet Hotel');

    aiBlockCall($hotel, AiAgentName::CONCIERGE, 100, 10);

    $encoded = strtolower(json_encode(
        $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($hotel), 'sanctum')
            ->getJson('/api/usage')
            ->assertOk()
            ->json('body')
    ));

    expect($encoded)->not->toContain('gemini')
        ->and($encoded)->not->toContain('"model"')
        ->and($encoded)->not->toContain('"provider"')
        ->and($encoded)->not->toContain('1.25');
});

it('keeps account AI usage away from employees', function () {
    $hotel = aiBlockHotel('Staffed');
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create();
    $employee->update(['hotel_id' => $hotel->id]);

    $this->withHeaders(aiBlockHeaders())->actingAs($employee->fresh(), 'sanctum')
        ->getJson('/api/usage')
        ->assertForbidden();
});

it('gives super admins the same AI block per account, and nobody else the cross-account report', function () {
    $hotel = aiBlockHotel('Reported');
    aiBlockCall($hotel, AiAgentName::INSIGHTS, 70, 7);

    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $accounts = collect($this->withHeaders(aiBlockHeaders())->actingAs($super, 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertOk()
        ->json('body.accounts'));

    $account = $accounts->firstWhere('hotel_group_id', $hotel->hotel_group_id);

    expect($account['ai']['requests'])->toBe(1)
        ->and($account['ai']['by_capability']['insights']['total_tokens'])->toBe(77);

    $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($hotel), 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertForbidden();

    $employee = User::factory()->role(UserRole::EMPLOYEE)->create();
    $employee->update(['hotel_id' => $hotel->id]);

    $this->withHeaders(aiBlockHeaders())->actingAs($employee->fresh(), 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertForbidden();
});

it('keeps every call in a hotel row: deleted hotels keep theirs, calls with no hotel are unassigned', function () {
    $main = aiBlockHotel('Main');
    $closed = aiBlockHotel('Closed annex', $main->hotel_group_id);

    aiBlockCall($main, AiAgentName::CONCIERGE, 100, 10);
    aiBlockCall($closed, AiAgentName::CONCIERGE, 200, 20);
    AiUsageLog::create([
        'hotel_group_id' => $main->hotel_group_id,
        'hotel_id' => null,
        'agent' => 'Embeddings',
        'provider' => 'gemini',
        'model' => 'gemini-embedding',
        'operation' => 'embedding',
        'input_tokens' => 30,
        'output_tokens' => 0,
        'cost_usd' => 0.01,
        'trigger_kind' => 'scheduled_job',
        'capability' => AiAgentName::KNOWLEDGE->value,
        'occurred_at' => now(),
    ]);

    $admin = aiBlockAdmin($main);
    $closed->delete();

    $ai = $this->withHeaders(aiBlockHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.ai');

    expect($ai['by_hotel'][$closed->id])->toMatchArray(['hotel' => 'Closed annex', 'requests' => 1])
        ->and($ai['by_hotel']['unassigned'])->toMatchArray(['hotel' => null, 'requests' => 1, 'input_tokens' => 30])
        ->and(array_sum(array_column($ai['by_hotel'], 'requests')))->toBe($ai['requests']);
});

it('adds calls with an unknown capability to the unattributed bucket rather than replacing it', function () {
    $hotel = aiBlockHotel('Mixed');

    aiBlockCall($hotel, AiAgentName::UNATTRIBUTED, 10, 1);
    aiBlockCall($hotel, AiAgentName::UNATTRIBUTED, 10, 1);
    DB::table('ai_usage_logs')->insert([
        'id' => (string) Str::uuid7(),
        'hotel_group_id' => $hotel->hotel_group_id,
        'hotel_id' => $hotel->id,
        'agent' => 'LegacyAgent',
        'provider' => 'gemini',
        'model' => 'old-model',
        'operation' => 'chat',
        'input_tokens' => 5,
        'output_tokens' => 1,
        'cached_tokens' => 0,
        'tool_calls' => 0,
        'cost_usd' => 0,
        'cost_is_estimated' => false,
        'succeeded' => true,
        'attempt' => 1,
        'trigger_kind' => 'guest_message',
        'capability' => 'retired_capability',
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ai = $this->withHeaders(aiBlockHeaders())->actingAs(aiBlockAdmin($hotel), 'sanctum')
        ->getJson('/api/usage')
        ->assertOk()
        ->json('body.ai');

    expect($ai['by_capability']['unattributed']['requests'])->toBe(3)
        ->and($ai['requests'])->toBe(3);
});

it('lists every account\'s hotels in the super-admin report with one hotels query', function () {
    foreach (range(1, 4) as $i) {
        aiBlockCall(aiBlockHotel("Account {$i}"), AiAgentName::CONCIERGE, 10, 1);
    }

    $super = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    DB::enableQueryLog();
    $this->withHeaders(aiBlockHeaders())->actingAs($super, 'sanctum')
        ->getJson('/api/admin/usage')
        ->assertOk();

    $hotelQueries = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from "hotels"/', $q['query']));

    expect($hotelQueries)->toHaveCount(1);
});
