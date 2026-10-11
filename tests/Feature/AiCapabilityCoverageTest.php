<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Agents\GuestMemoryAgent;
use App\Ai\Agents\InsightsAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\CreateRecommendationTool;
use App\Enums\AiAgentName;
use App\Enums\InboundMessageStatus;
use App\Enums\MeterFeature;
use App\Enums\SenderType;
use App\Jobs\CreateAiInsightsJob;
use App\Jobs\GenerateActivityRecommendationsJob;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\AiUsageLog;
use App\Models\KnowledgeBaseArticle;
use App\Models\MeterEvent;
use App\Models\WhatsAppInboundMessage;
use App\Services\Metering\MeteringService;
use App\Services\StayLifecycleService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

/*
| Every AI capability is metered and cost-attributed (SPEC-091 FR-018,
| FR-022, FR-023). The mapping below declares which usage features each
| capability's work is counted by; these tests prove each capability
| actually records what it declares, and that nothing it does is filed as
| unattributed.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    AiCostContext::reset();
});

afterEach(function () {
    AiCostContext::reset();
});

/**
 * The usage features a capability's work is counted by. Exhaustive on
 * purpose, with no default: a capability added to AiAgentName without a
 * mapping throws here, naming itself, instead of shipping unmetered.
 *
 * The turn classifier only ever runs inside a Concierge turn, which is
 * metered as an AI message; the Concierge also generates recommendations
 * inline when it pitches.
 *
 * @return array<int, MeterFeature>
 */
function capabilityMeters(AiAgentName $capability): array
{
    return match ($capability) {
        AiAgentName::CONCIERGE => [MeterFeature::AI_MESSAGES, MeterFeature::RECOMMENDATIONS_GENERATED],
        AiAgentName::ADMIN_ADVISOR => [MeterFeature::AI_MESSAGES],
        AiAgentName::RECOMMENDATION => [MeterFeature::RECOMMENDATIONS_GENERATED],
        AiAgentName::INSIGHTS => [MeterFeature::AI_INSIGHTS_GENERATED],
        AiAgentName::TURN_SIGNAL => [MeterFeature::AI_MESSAGES],
        AiAgentName::DOCUMENT_VISION => [MeterFeature::KNOWLEDGE_PAGES_READ, MeterFeature::EMBEDDINGS_GENERATED],
        AiAgentName::GUEST_MEMORY => [MeterFeature::GUEST_MEMORY_EXTRACTIONS],
        AiAgentName::KNOWLEDGE => [MeterFeature::EMBEDDINGS_GENERATED],
        AiAgentName::UNATTRIBUTED => [],
    };
}

/**
 * The capability left provider calls behind under the right name, its work
 * was metered by one of its declared features, and nothing was unattributed.
 */
function expectCapabilityCovered(AiAgentName $capability): void
{
    $logs = AiUsageLog::query()->where('capability', $capability->value)->get();

    expect($logs)->not->toBeEmpty("{$capability->value} made no attributed provider call")
        ->and($logs->every(fn (AiUsageLog $log) => $log->trigger_kind->value !== 'unattributed'))->toBeTrue();

    $features = array_map(fn (MeterFeature $feature) => $feature->value, capabilityMeters($capability));

    expect(MeterEvent::query()->whereIn('feature_code', $features)->exists())
        ->toBeTrue("{$capability->value} recorded none of its usage features");

    expect(AiUsageLog::query()->where('capability', AiAgentName::UNATTRIBUTED->value)->orWhere('trigger_kind', 'unattributed')->count())
        ->toBe(0);
}

it('maps every capability to at least one usage feature', function () {
    foreach (AiAgentName::cases() as $capability) {
        $features = capabilityMeters($capability);

        if ($capability === AiAgentName::UNATTRIBUTED) {
            expect($features)->toBe([]);

            continue;
        }

        expect($features)->not->toBeEmpty("{$capability->value} has no usage feature")
            ->and(collect($features)->contains(fn (MeterFeature $feature) => $feature->isSeat()))->toBeFalse();
    }
});

it('meters and attributes a Concierge turn', function () {
    $hotel = cmaHotel();
    [$guest] = rapInHouseStay($hotel, ['phone_number' => '+201000000901']);

    GuestConciergeAgent::fake(['Breakfast is from 7 to 10.']);
    $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldReceive('send')->andReturn(true));

    (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => '+201000000901', 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: '+201000000901',
        messageText: 'What time is breakfast?',
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $hotel,
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    expectCapabilityCovered(AiAgentName::CONCIERGE);
});

it('meters and attributes an Admin AI turn on the web', function () {
    $hotel = cmaHotel();
    AdminAdvisorAgent::fake(['Two arrivals today.']);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs(cmaAdmin($hotel), 'sanctum')
        ->postJson('/api/ai-advisor/chat', ['message' => 'Who arrives today?'])
        ->assertOk();

    expectCapabilityCovered(AiAgentName::ADMIN_ADVISOR);
});

it('meters and attributes an Admin AI turn on WhatsApp', function () {
    $hotel = cmaHotel();
    $admin = cmaAdmin($hotel);
    AdminAdvisorAgent::fake(['Two arrivals today.']);
    $this->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldReceive('send')->andReturn(true));

    (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => '+201000000902', 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: '+201000000902',
        messageText: 'Who arrives today?',
        senderType: SenderType::ADMIN,
        sender: $admin,
        hotel: $hotel,
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    expectCapabilityCovered(AiAgentName::ADMIN_ADVISOR);
});

it('meters and attributes insights', function () {
    $hotel = cmaHotel();
    InsightsAgent::fake([['insights' => [
        ['title' => 'Busy weekend', 'description' => 'Arrivals peak on Friday.', 'category' => 'general', 'source_id' => $hotel->id, 'citations' => []],
    ]]]);

    (new CreateAiInsightsJob(['user' => cmaAdmin($hotel), 'insight_type' => 'general']))->handle(app(MeteringService::class));

    expectCapabilityCovered(AiAgentName::INSIGHTS);
});

it('meters and attributes scheduled recommendation generation', function () {
    $hotel = cmaHotel();
    [, $reservation] = rapInHouseStay($hotel);
    $kayak = rapActivity($hotel, 'Kayaking');

    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });
    RecommendationAgent::fake(function () use (&$agent, $kayak) {
        toolOfClass($agent->tools(), CreateRecommendationTool::class)
            ->handle(new Request(['activity_id' => $kayak->id, 'reason' => 'Fits the party', 'predicted_confidence' => 0.7]));

        return 'Done.';
    });

    (new GenerateActivityRecommendationsJob($reservation))->handle(app(MeteringService::class));

    expectCapabilityCovered(AiAgentName::RECOMMENDATION);
});

it('meters and attributes knowledge indexing', function () {
    knFakeEmbeddings();
    $hotel = cmaHotel();

    // The sync job runs on save (sync queue in tests).
    KnowledgeBaseArticle::create(['hotel_id' => $hotel->id, 'title' => 'Breakfast', 'content' => 'Breakfast is served from 7 to 10 in the garden.', 'status' => 'published']);

    expectCapabilityCovered(AiAgentName::KNOWLEDGE);
});

it('meters and attributes document reading', function () {
    Storage::fake('local');
    knFakeEmbeddings();
    knFakeVision([['page' => 1, 'text' => 'SPA OPEN 09:00-21:00', 'description' => 'A printed sign.']]);
    [$admin] = knHotel();

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->post('/api/knowledge-documents', ['file' => knUpload('sign.png'), 'title' => 'Spa sign'], ['Accept' => 'application/json'])
        ->assertCreated();

    expectCapabilityCovered(AiAgentName::DOCUMENT_VISION);
});

it('meters and attributes guest memory extraction', function () {
    $hotel = cmaHotel();
    [$guest, , $stay] = rapInHouseStay($hotel);
    cmaGuestConversation($guest, [['role' => 'user', 'content' => 'I am vegetarian.', 'at' => now()->subHour()]]);
    GuestMemoryAgent::fake([['operations' => [
        ['op' => 'create', 'id' => null, 'category' => 'preference', 'content' => 'Vegetarian.', 'language' => 'en', 'confidence' => 'high'],
    ]]]);

    app(StayLifecycleService::class)->checkOut($stay->fresh());

    expectCapabilityCovered(AiAgentName::GUEST_MEMORY);
});
