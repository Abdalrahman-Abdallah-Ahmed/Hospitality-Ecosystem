<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AiActivity;
use App\Models\AiUsageLog;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * SPEC-083 FR-025, FR-035: the AI activity list — everything the AI did for
 * the hotel, filterable, with cost only for super admins.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function activityRow(Hotel $hotel, array $attributes = []): AiActivity
{
    return AiActivity::create([
        'hotel_id' => $hotel->id,
        'category' => 'data_change',
        'agent' => 'concierge',
        'tool' => 'CreateBookingTool',
        'action' => 'booking.created',
        'result' => 'succeeded',
        'occurred_at' => now(),
        ...$attributes,
    ]);
}

it('lists the hotel\'s AI activity newest first, with a link to each record\'s history', function () {
    $hotel = cmaHotel();
    $booking = Str::uuid()->toString();
    $older = activityRow($hotel, ['occurred_at' => now()->subHour(), 'target_type' => 'App\\Models\\Booking', 'target_id' => $booking]);
    $newer = activityRow($hotel, ['category' => 'ai_run', 'tool' => null, 'action' => 'run.completed', 'usage' => ['requests' => 2, 'input_tokens' => 100, 'output_tokens' => 20, 'cached_tokens' => 0], 'trigger_kind' => 'guest_message']);

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', '/api/ai-activity')->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($response->json('body.data.0.usage'))->toBe(['requests' => 2, 'input_tokens' => 100, 'output_tokens' => 20, 'cached_tokens' => 0])
        ->and($response->json('body.data.0.trigger_kind'))->toBe('guest_message')
        ->and($response->json('body.data.1.target'))->toBe(['type' => 'booking', 'id' => $booking, 'history_url' => "/api/history/booking/{$booking}"])
        ->and($response->json('body.data.1'))->not->toHaveKey('usage')
        ->and($response->json('body.meta.per_page'))->toBe(25);
});

it('filters by every field the contract names', function (string $query, Closure $expected) {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $rows = [
        'run' => activityRow($hotel, ['category' => 'ai_run', 'tool' => null, 'action' => 'run.completed', 'ai_run_id' => $runId = Str::uuid()->toString(), 'conversation_id' => 'conv-a']),
        'refused' => activityRow($hotel, ['result' => 'refused', 'agent' => 'admin_advisor', 'tool' => 'CreateGuestTool', 'action' => 'guest.refused']),
        'outreach' => activityRow($hotel, ['category' => 'guest_outreach', 'tool' => 'SendProactiveMessageJob', 'action' => 'proactive.sent', 'acted_for_type' => $guest->getMorphClass(), 'acted_for_id' => $guest->id]),
        'booking' => activityRow($hotel, ['ai_run_id' => $runId, 'conversation_id' => 'conv-a', 'target_type' => 'App\\Models\\Booking', 'target_id' => Str::uuid()->toString(), 'occurred_at' => now()->subDays(3)]),
    ];

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', '/api/ai-activity?'.str_replace('{guest}', $guest->id, $query))->assertOk();

    $ids = collect($response->json('body.data'))->pluck('id')->sort()->values()->all();
    $want = collect($expected())->map(fn (string $key) => $rows[$key]->id)->sort()->values()->all();

    expect($ids)->toBe($want);
})->with([
    'one category' => ['category=ai_run', fn () => ['run']],
    'two categories' => ['category=ai_run,guest_outreach', fn () => ['run', 'outreach']],
    'agent' => ['agent=admin_advisor', fn () => ['refused']],
    'tool' => ['tool=SendProactiveMessageJob', fn () => ['outreach']],
    'result' => ['result=refused', fn () => ['refused']],
    'conversation' => ['conversation_id=conv-a', fn () => ['run', 'booking']],
    'acted for' => ['acted_for_type=guest&acted_for_id={guest}', fn () => ['outreach']],
    'target type' => ['target_type=booking', fn () => ['booking']],
    'date range' => ['to='.now('Asia/Riyadh')->subDays(2)->toDateString(), fn () => ['booking']],
]);

it('filters by run', function () {
    $hotel = cmaHotel();
    $run = Str::uuid()->toString();
    $inRun = activityRow($hotel, ['ai_run_id' => $run]);
    activityRow($hotel);

    $response = cmaRequest($this, cmaAdmin($hotel), 'GET', "/api/ai-activity?ai_run_id={$run}")->assertOk();

    expect(collect($response->json('body.data'))->pluck('id')->all())->toBe([$inRun->id]);
});

it('shows a run\'s provider cost to super admins only', function () {
    $hotel = cmaHotel();
    $run = activityRow($hotel, ['category' => 'ai_run', 'tool' => null, 'action' => 'run.completed', 'usage' => ['requests' => 2, 'input_tokens' => 10, 'output_tokens' => 1, 'cached_tokens' => 0]]);
    AiActivity::query()->whereKey($run->id)->update(['ai_run_id' => $run->id]);

    foreach ([0.0125, 0.0075] as $cost) {
        AiUsageLog::create([
            'hotel_group_id' => $hotel->hotel_group_id,
            'hotel_id' => $hotel->id,
            'agent' => 'GuestConciergeAgent',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'operation' => 'chat',
            'cost_usd' => $cost,
            'cost_is_estimated' => false,
            'succeeded' => true,
            'attempt' => 1,
            'trigger_kind' => 'guest_message',
            'occurred_at' => now(),
            'ai_run_id' => $run->id,
        ]);
    }

    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $asAdmin = cmaRequest($this, cmaAdmin($hotel), 'GET', '/api/ai-activity')->assertOk();
    $asSuper = cmaRequest($this, $superAdmin, 'GET', "/api/ai-activity?hotel_id={$hotel->id}")->assertOk();

    expect($asAdmin->json('body.data.0'))->not->toHaveKey('cost_usd')
        ->and($asAdmin->getContent())->not->toContain('cost')
        ->and((float) $asSuper->json('body.data.0.cost_usd'))->toBe(0.02);

    cmaRequest($this, $superAdmin, 'GET', '/api/ai-activity')->assertUnprocessable();
});

it('is open to employees only through history.view', function () {
    $hotel = cmaHotel();
    activityRow($hotel);

    cmaRequest($this, cmaEmployee($hotel, Permission::employeeDefaults()), 'GET', '/api/ai-activity')->assertForbidden();
    cmaRequest($this, cmaEmployee($hotel, [Permission::HISTORY_VIEW]), 'GET', '/api/ai-activity')->assertOk();
});

it('has no write routes', function () {
    $hotel = cmaHotel();

    cmaRequest($this, cmaAdmin($hotel), 'POST', '/api/ai-activity', [])->assertStatus(405);
});
