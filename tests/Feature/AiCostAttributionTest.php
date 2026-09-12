<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Enums\AiOperation;
use App\Enums\AiTriggerKind;
use App\Enums\KnowledgeBaseCategory;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Jobs\AiCost\FlagAiCostOverrunsJob;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\AiModelPrice;
use App\Models\AiUsageLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\User;
use App\Services\AiCost\AiCostRecorder;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    config(['ai_cost.fx.usd_per_eur' => 1.09]);
    config(['ai_cost.daily_ceiling_usd' => 50.00]);
    config(['ai_cost.alert_margin_threshold' => 0.50]);

    AiCostContext::reset();

    // The app's default text model, priced so the arithmetic in these tests
    // is checkable by hand: $1 per million in, $10 per million out.
    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_price_per_million' => 1.0000,
        'output_price_per_million' => 10.0000,
        'cached_input_price_per_million' => 0.1000,
        'currency' => 'USD',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
});

afterEach(function () {
    AiCostContext::reset();
});

function costHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function costHotel(string $name = 'Cost Hotel'): Hotel
{
    return Hotel::create([
        'name' => $name,
        'slug' => 'cost-'.uniqid(),
        'currency' => 'EUR',
    ]);
}

function recorder(): AiCostRecorder
{
    return app(AiCostRecorder::class);
}

/**
 * Log one call as though a provider had just answered.
 */
function logCall(Hotel $hotel, AiTriggerKind $kind, array $attributes = []): AiUsageLog
{
    return AiCostContext::for(
        kind: $kind,
        hotel: $hotel,
        callback: fn () => recorder()->record(...array_merge([
            'agent' => 'GuestConciergeAgent',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'operation' => AiOperation::CHAT,
            'inputTokens' => 1_000_000,
            'outputTokens' => 0,
        ], $attributes)),
    );
}

it('logs cost for every agent call including failures and retries', function () {
    $hotel = costHotel();
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => 'Ada',
        'last_name' => 'Byron',
        'phone' => '+201000000001',
    ]);

    GuestConciergeAgent::fake(['Of course, happy to help!']);

    $this->mock(WhatsAppMessageService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturn(true);
    });

    (new ProcessInboundWhatsAppMessageJob(
        phoneNumber: '+201000000001',
        messageText: 'What time is breakfast?',
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $hotel,
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    // The agent call was logged without the job, the agent, or the tools
    // being told to log anything: capture is central.
    $log = AiUsageLog::where('operation', AiOperation::CHAT->value)->first();

    expect($log)->not->toBeNull()
        ->and($log->agent)->toBe('GuestConciergeAgent')
        ->and($log->hotel_group_id)->toBe($hotel->hotel_group_id)
        ->and($log->trigger_kind)->toBe(AiTriggerKind::GUEST_MESSAGE)
        ->and($log->succeeded)->toBeTrue()
        ->and($log->attempt)->toBe(1);

    // A retry is a second row, not an overwrite of the first: the retry cost
    // real money and a total that hides it understates the bill.
    $hotelForRetry = costHotel('Retry Hotel');

    AiCostContext::for(
        kind: AiTriggerKind::STAFF_REQUEST,
        hotel: $hotelForRetry,
        callback: function () {
            AiCostContext::nextAttempt();
            recorder()->record(
                agent: 'AdminAdvisorAgent', provider: 'openai', model: 'gpt-5.4',
                operation: AiOperation::CHAT, succeeded: false, failureReason: 'RateLimited',
            );

            AiCostContext::nextAttempt();
            recorder()->record(
                agent: 'AdminAdvisorAgent', provider: 'openai', model: 'gpt-5.4',
                operation: AiOperation::CHAT, inputTokens: 500_000,
            );
        },
    );

    $attempts = AiUsageLog::where('hotel_group_id', $hotelForRetry->hotel_group_id)
        ->orderBy('attempt')->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->succeeded)->toBeFalse()
        ->and($attempts[0]->attempt)->toBe(1)
        ->and($attempts[0]->failure_reason)->toBe('RateLimited')
        ->and($attempts[1]->succeeded)->toBeTrue()
        ->and($attempts[1]->attempt)->toBe(2);
});

it('uses the price effective on the date of the call, not today\'s price', function () {
    $hotel = costHotel();

    // The model gets more expensive from September: $4 per million in.
    AiModelPrice::supersede(
        provider: 'openai',
        model: 'gpt-5.4',
        inputPerMillion: 4.0000,
        outputPerMillion: 10.0000,
        cachedInputPerMillion: 0.1000,
        effectiveFrom: Carbon::parse('2026-09-01'),
    );

    $august = logCall($hotel, AiTriggerKind::STAFF_REQUEST, [
        'occurredAt' => Carbon::parse('2026-08-15 10:00:00'),
    ]);

    $september = logCall($hotel, AiTriggerKind::STAFF_REQUEST, [
        'occurredAt' => Carbon::parse('2026-09-15 10:00:00'),
    ]);

    // Same call, same tokens, different months: August must still cost what
    // August cost, or no figure can be reconciled against its invoice.
    expect((float) $august->cost_usd)->toBe(1.0)
        ->and((float) $september->cost_usd)->toBe(4.0);

    // The old price was superseded, not overwritten.
    $prices = AiModelPrice::where('model', 'gpt-5.4')->orderBy('effective_from')->get();

    expect($prices)->toHaveCount(2)
        ->and($prices[0]->effective_to->toDateString())->toBe('2026-08-31')
        ->and($prices[1]->effective_to)->toBeNull();
});

it('attributes a guest-triggered call to the right hotel group', function () {
    $ours = costHotel('Ours');
    $theirs = costHotel('Theirs');

    logCall($ours, AiTriggerKind::GUEST_MESSAGE);

    $log = AiUsageLog::first();

    expect($log->hotel_group_id)->toBe($ours->hotel_group_id)
        ->and($log->hotel_id)->toBe($ours->id)
        ->and($log->hotel_group_id)->not->toBe($theirs->hotel_group_id);
});

it('marks estimated costs and reports the estimated percentage', function () {
    $hotel = costHotel();
    $admin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    // One priced call, and one on a model nobody has priced.
    logCall($hotel, AiTriggerKind::STAFF_REQUEST);
    $unpriced = logCall($hotel, AiTriggerKind::STAFF_REQUEST, ['model' => 'gpt-nobody-priced']);

    expect($unpriced->cost_is_estimated)->toBeTrue()
        ->and((float) $unpriced->cost_usd)->toBe(0.0);

    $response = $this->withHeaders(costHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertStatus(200);

    $account = collect($response->json('body.accounts'))
        ->firstWhere('hotel_group_id', $hotel->hotel_group_id);

    // Half the rows are estimated, and the total says so rather than
    // presenting itself as measured.
    expect($account['cost_basis'])->toBe('mixed')
        ->and($account['estimated_rows_pct'])->toEqual(50.0);

    // A zero that means "not priced" is named, so it is never read as free.
    expect($response->json('body.unpriced_models'))->toHaveCount(1)
        ->and($response->json('body.unpriced_models.0.model'))->toBe('openai/gpt-nobody-priced');
});

it('separates cost by trigger kind', function () {
    $hotel = costHotel();
    $admin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    // 1.09 USD each, so exactly 1.00 EUR each at the test rate.
    logCall($hotel, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 1_090_000]);
    logCall($hotel, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 1_090_000]);
    logCall($hotel, AiTriggerKind::STAFF_REQUEST, ['inputTokens' => 1_090_000]);
    logCall($hotel, AiTriggerKind::SCHEDULED_JOB, ['inputTokens' => 1_090_000]);

    $response = $this->withHeaders(costHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertStatus(200);

    $account = collect($response->json('body.accounts'))
        ->firstWhere('hotel_group_id', $hotel->hotel_group_id);

    // The split that matters: guest-driven spend is the half nothing we
    // decide bounds.
    expect($account['by_trigger']['guest_message'])->toEqual(2.0)
        ->and($account['by_trigger']['staff_request'])->toEqual(1.0)
        ->and($account['by_trigger']['scheduled_job'])->toEqual(1.0)
        ->and($account['by_trigger']['unattributed'])->toEqual(0.0);
});

it('returns null margin when no contract value is recorded', function () {
    $withContract = costHotel('Paying');
    $withoutContract = costHotel('Unknown');
    $admin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    $withContract->hotelGroup->update([
        'contract_value_monthly' => 1042.00,
        'contract_currency' => 'EUR',
    ]);

    // 109 USD = 100 EUR at the test rate.
    logCall($withContract, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 109_000_000]);
    logCall($withoutContract, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 109_000_000]);

    $response = $this->withHeaders(costHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertStatus(200);

    $accounts = collect($response->json('body.accounts'));
    $paying = $accounts->firstWhere('hotel_group_id', $withContract->hotel_group_id);
    $unknown = $accounts->firstWhere('hotel_group_id', $withoutContract->hotel_group_id);

    expect($paying['gross_margin'])->toEqual(942.0)
        ->and($paying['gross_margin_pct'])->toEqual(90.4)
        ->and($paying['margin_basis'])->toBe('manually recorded contract value; not invoiced');

    // Null, not zero, and with the reason attached.
    expect($unknown['gross_margin'])->toBeNull()
        ->and($unknown['gross_margin_pct'])->toBeNull()
        ->and($unknown['margin_unavailable_reason'])->toContain('No contract value recorded');

    // The FX rate that produced these figures is stated, not implied.
    expect($response->json('body.currency_note.usd_per_eur'))->toEqual(1.09)
        ->and($response->json('body.currency_note.converted_at'))->toBe('read time')
        ->and($response->json('body.notes'))->toContain('1.09 USD/EUR');
});

it('does not lose the AI response when cost logging fails', function () {
    [$admin, $hotel] = (function () {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $hotel = Hotel::create([
            'owner_id' => $admin->id,
            'name' => 'Advisor Hotel',
            'slug' => 'advisor-'.$admin->id,
            'currency' => 'EUR',
        ]);
        $admin->update(['hotel_id' => $hotel->id]);

        return [$admin->fresh(), $hotel];
    })();

    AdminAdvisorAgent::fake(['Here is some advice about your hotel.']);

    // The cost write itself is broken. safely() is left real, because it is
    // the thing under test: the guarantee is that it absorbs this.
    $this->partialMock(AiCostRecorder::class, function ($mock) {
        $mock->shouldReceive('record')->andThrow(new RuntimeException('cost logging is down'));
    });

    // The advisor still answers. A lost cost row is a line in a report; a
    // lost answer is a customer.
    $this->withHeaders(costHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'How are we doing?'])
        ->assertStatus(200)
        ->assertJsonPath('body.reply', 'Here is some advice about your hotel.');

    expect(AiUsageLog::count())->toBe(0);
});

it('logs embeddings from the knowledge base sync', function () {
    $hotel = costHotel();

    AiModelPrice::create([
        'provider' => 'openai',
        'model' => 'text-embedding-3-small',
        'input_price_per_million' => 0.0200,
        'output_price_per_million' => 0.0000,
        'cached_input_price_per_million' => null,
        'currency' => 'USD',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);

    Embeddings::fake();

    $article = KnowledgeBaseArticle::withoutEvents(fn () => KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => 'Breakfast times',
        'content' => str_repeat('Breakfast is served from 7am to 10:30am in the main restaurant. ', 20),
        'category' => KnowledgeBaseCategory::HOSPITALITY_BEST_PRACTICES->value,
        'is_active' => true,
    ]));

    (new SyncKnowledgeChunksJob($article))->handle(app(MeteringService::class));

    $log = AiUsageLog::where('operation', AiOperation::EMBEDDING->value)->first();

    // Pure cost with no visible output, and the line everyone forgets.
    expect($log)->not->toBeNull()
        ->and($log->hotel_group_id)->toBe($hotel->hotel_group_id)
        ->and($log->trigger_kind)->toBe(AiTriggerKind::SCHEDULED_JOB)
        ->and($log->trigger_type)->toBe($article->getMorphClass())
        ->and($log->trigger_id)->toBe($article->id);
});

it('attributes an embedding made inside a guest conversation to that guest', function () {
    $hotel = costHotel();

    Embeddings::fake();

    // A knowledge-base search inside a conversation is guest-driven cost,
    // even though the embedding call itself knows nothing about guests.
    AiCostContext::for(
        kind: AiTriggerKind::GUEST_MESSAGE,
        hotel: $hotel,
        callback: fn () => Embeddings::for(['what time is breakfast'])->dimensions(1536)->generate(),
    );

    $log = AiUsageLog::where('operation', AiOperation::EMBEDDING->value)->first();

    expect($log)->not->toBeNull()
        ->and($log->trigger_kind)->toBe(AiTriggerKind::GUEST_MESSAGE)
        ->and($log->hotel_group_id)->toBe($hotel->hotel_group_id);
});

it('records an undeclared call as unattributed rather than guessing its trigger', function () {
    Embeddings::fake();

    // No context at all, and no tenant scope to fall back on.
    Embeddings::for(['an orphaned call'])->dimensions(1536)->generate();

    $log = AiUsageLog::first();

    // Still logged — that is the point of central capture — but filed
    // honestly rather than assigned to whichever kind looked likely.
    expect($log)->not->toBeNull()
        ->and($log->trigger_kind)->toBe(AiTriggerKind::UNATTRIBUTED)
        ->and($log->hotel_group_id)->toBeNull();
});

it('stops an account that has passed its hard daily ceiling', function () {
    $hotel = costHotel();

    config(['ai_cost.daily_ceiling_usd' => 10.00]);

    // $12 spent today, past the $10 ceiling.
    logCall($hotel, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 12_000_000]);

    expect(fn () => AiCostContext::for(
        kind: AiTriggerKind::GUEST_MESSAGE,
        hotel: $hotel,
        callback: fn () => 'should not run',
    ))->toThrow(AiSpendCeilingExceededException::class);

    // A different account is untouched: the ceiling bounds one account, not
    // the system.
    $other = costHotel('Untouched');

    expect(AiCostContext::for(
        kind: AiTriggerKind::GUEST_MESSAGE,
        hotel: $other,
        callback: fn () => 'ran fine',
    ))->toBe('ran fine');
});

it('alerts on a thin margin without throttling anything', function () {
    $hotel = costHotel();

    // Deliberately out of the way: this test is about the alert, and the
    // abuse stop is a different mechanism with a different purpose.
    config(['ai_cost.daily_ceiling_usd' => 1000.00]);
    $hotel->hotelGroup->update([
        'contract_value_monthly' => 100.00,
        'contract_currency' => 'EUR',
    ]);

    // 65.40 USD = 60 EUR, which is 60% of a 100 EUR contract.
    logCall($hotel, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 65_400_000]);

    $flagged = (new FlagAiCostOverrunsJob)->handle();

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]['share_of_contract_pct'])->toBe(60.0)
        ->and($flagged[0]['action'])->toContain('Alert only');

    // Alerting must not have limited the account: the next call still runs.
    expect(AiCostContext::for(
        kind: AiTriggerKind::GUEST_MESSAGE,
        hotel: $hotel,
        callback: fn () => 'still served',
    ))->toBe('still served');
});

it('prices cached input separately from fresh input', function () {
    $hotel = costHotel();

    // 1M fresh at $1, 1M cached at $0.10, 1M out at $10.
    $log = logCall($hotel, AiTriggerKind::STAFF_REQUEST, [
        'inputTokens' => 1_000_000,
        'cachedTokens' => 1_000_000,
        'outputTokens' => 1_000_000,
    ]);

    // Cached tokens are not included in input_tokens by the AI package, so
    // charging both at the full input rate would overstate the bill.
    expect((float) $log->cost_usd)->toBe(11.1)
        ->and($log->cost_is_estimated)->toBeFalse();
});

it('keeps ai usage logs append-only', function () {
    $hotel = costHotel();
    $log = logCall($hotel, AiTriggerKind::STAFF_REQUEST);

    expect(fn () => $log->update(['cost_usd' => 0]))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class);
});

it('stores cost in usd only, converting at read time', function () {
    // The schema holds what the provider charged, in the currency it charged
    // it. A converted figure written at write time would bake one day's rate
    // into a permanent row and make history unreproducible.
    expect(Schema::hasColumn('ai_usage_logs', 'cost_usd'))->toBeTrue()
        ->and(Schema::hasColumn('ai_usage_logs', 'cost_eur'))->toBeFalse()
        ->and(Schema::hasColumn('ai_usage_logs', 'fx_rate'))->toBeFalse();

    // Six decimals, because a single call may cost $0.000420 and two
    // decimals would round the entire cost base to zero.
    $hotel = costHotel();
    $log = logCall($hotel, AiTriggerKind::STAFF_REQUEST, ['inputTokens' => 420]);

    expect((float) $log->cost_usd)->toBe(0.00042);
});

it('reports a cost of fractions of a cent instead of rounding it away', function () {
    $hotel = costHotel();
    $admin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    // A real month for a small account: twelve calls totalling well under a
    // cent. Rounding money to cents at read time would report this hotel as
    // costing nothing to serve, which is never true of a hotel that made
    // calls — the same mistake decimal(12,6) exists to prevent at write time.
    foreach (range(1, 12) as $ignored) {
        logCall($hotel, AiTriggerKind::GUEST_MESSAGE, ['inputTokens' => 109]);
    }

    $response = $this->withHeaders(costHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertStatus(200);

    $account = collect($response->json('body.accounts'))
        ->firstWhere('hotel_group_id', $hotel->hotel_group_id);

    // 12 x 109 tokens at $1/M = $0.001308, and 109 USD per 100 EUR.
    expect($account['calls'])->toBe(12)
        ->and($account['ai_cost_usd'])->toEqual(0.001308)
        ->and($account['ai_cost_eur'])->toEqual(0.0012)
        ->and($account['by_trigger']['guest_message'])->toEqual(0.0012)
        ->and($account['cost_per_call_eur'])->toEqual(0.0001);
});

it('blocks a non super admin from the ai cost report', function () {
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = costHotel();
    $admin->update(['hotel_id' => $hotel->id]);

    // The cost report reads every account, and cost_usd is our own cost of
    // goods. A hotel admin reaching it would see both other customers' usage
    // and our margin on their own contract.
    $this->withHeaders(costHeaders())->actingAs($admin->fresh(), 'sanctum')
        ->getJson('/api/admin/ai-cost')
        ->assertStatus(403);
});

it('keeps the split-out admin routes behind the full middleware stack', function () {
    // routes/admin.php is registered in bootstrap/app.php rather than nested
    // inside routes/api.php. This asserts the move did not quietly drop a
    // layer — the failure mode of that refactor is a route that still answers
    // but no longer checks who is asking.
    // The aliases as declared, in order. Their resolution to real classes is
    // covered by the 403 tests either side of this one.
    $expected = ['api', 'api.key', 'auth:sanctum', 'tenant', 'super_admin'];

    foreach (['api/admin/usage', 'api/admin/ai-cost'] as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === $uri);

        expect($route)->not->toBeNull("route {$uri} is missing")
            ->and($route->gatherMiddleware())->toBe($expected);
    }
});
