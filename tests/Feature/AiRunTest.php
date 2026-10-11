<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Enums\AiOperation;
use App\Enums\AiTriggerKind;
use App\Enums\InboundMessageStatus;
use App\Enums\SenderType;
use App\Exceptions\AiSpendCeilingExceededException;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\AiActivity;
use App\Models\AiUsageLog;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\WhatsAppInboundMessage;
use App\Services\AiCost\AiCostRecorder;
use App\Services\AiCost\AiSpendCeiling;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use App\Support\Ai\AiCostContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-083 R2: every AI turn or job is one ai_run row, carrying its usage
 * summed from the provider calls it made, and linking everything it wrote.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    AiCostContext::reset();
});

function runGuestTurn(Guest $guest, string $text = 'What time is breakfast?'): void
{
    test()->mock(WhatsAppMessageService::class, fn ($mock) => $mock->shouldReceive('send')->andReturn(true));

    (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => $guest->phone_number, 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: $guest->phone_number,
        messageText: $text,
        senderType: SenderType::GUEST,
        sender: $guest,
        hotel: $guest->hotel,
        reservation: $guest->reservations()->first(),
        devicePaired: false,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));
}

function runRows(): Collection
{
    return AiActivity::withoutGlobalScope('hotel')->where('category', 'ai_run')->get();
}

it('writes one run row per Concierge turn, with its usage, conversation and guest', function () {
    $hotel = cmaHotel();
    [$guest] = rapInHouseStay($hotel);
    GuestConciergeAgent::fake(['Breakfast is from 7 to 10.']);

    runGuestTurn($guest);

    $run = runRows()->sole();
    $logs = AiUsageLog::where('ai_run_id', $run->id)->get();

    expect($run->agent->value)->toBe('concierge')
        ->and($run->trigger_kind)->toBe(AiTriggerKind::GUEST_MESSAGE)
        ->and($run->result->value)->toBe('succeeded')
        ->and($run->action)->toBe('run.completed')
        ->and($run->ai_run_id)->toBe($run->id)
        ->and($run->hotel_id)->toBe($hotel->id)
        ->and($run->acted_for_id)->toBe($guest->id)
        ->and($run->conversation_id)->not->toBeNull()
        ->and($logs)->not->toBeEmpty()
        ->and($run->usage)->toBe([
            'requests' => $logs->count(),
            'input_tokens' => (int) $logs->sum('input_tokens'),
            'output_tokens' => (int) $logs->sum('output_tokens'),
            'cached_tokens' => (int) $logs->sum('cached_tokens'),
        ])
        ->and(json_encode($run->getAttributes()))->not->toContain('cost');
});

it('links a write made during the turn to the run, in both the activity list and the audit trail', function () {
    $hotel = cmaHotel();
    [$guest] = rapInHouseStay($hotel);

    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });
    // The second call is the package titling the new conversation through
    // the same faked agent; only the turn itself calls the tool.
    $calls = 0;
    GuestConciergeAgent::fake(function () use (&$agent, &$calls) {
        if ($calls++ === 0) {
            toolOfClass($agent->tools(), CreateGuestServiceRequestTool::class)
                ->handle(new Request(['title' => 'Extra towels', 'description' => 'Two more towels please']));
        }

        return 'Done, towels are on their way.';
    });

    runGuestTurn($guest, 'Can I get extra towels?');

    $run = runRows()->sole();
    $change = AiActivity::withoutGlobalScope('hotel')->where('category', 'data_change')->sole();
    $event = EventLog::withoutGlobalScope('hotel')->where('subject_id', $change->target_id)->where('event_type', 'task.created')->sole();

    expect($change->ai_run_id)->toBe($run->id)
        ->and($change->result->value)->toBe('succeeded')
        ->and($change->conversation_id)->toBe($run->conversation_id)
        ->and($event->context['ai']['run_id'])->toBe($run->id);
});

it('fills in the conversation on a guest\'s very first message', function () {
    $hotel = cmaHotel();
    [$guest] = rapInHouseStay($hotel);
    expect($guest->conversations()->exists())->toBeFalse();

    $agent = null;
    Event::listen(PromptingAgent::class, function ($event) use (&$agent) {
        $agent = $event->prompt->agent;
    });
    $conversationId = null;
    GuestConciergeAgent::fake(function () use (&$agent, &$conversationId, $guest) {
        // The tool runs before the conversation exists...
        toolOfClass($agent->tools(), CreateGuestServiceRequestTool::class)
            ->handle(new Request(['title' => 'Extra pillow', 'description' => 'One more pillow']));

        // ...which the package's middleware creates only once the reply is
        // in. A faked agent skips that middleware, so do what it does.
        $conversationId = cmaGuestConversation($guest, [['role' => 'user', 'content' => 'Hi, can I get a pillow?']]);
        $agent->continue($conversationId, $guest);

        return 'On its way.';
    });

    runGuestTurn($guest, 'Hi, can I get a pillow?');

    $run = runRows()->sole();

    expect($run->conversation_id)->toBe($conversationId)
        ->and(AiActivity::withoutGlobalScope('hotel')->where('category', 'data_change')->sole()->conversation_id)->toBe($conversationId);
});

it('opens no extra run for nested AI calls', function () {
    $hotel = cmaHotel();

    AiCostContext::for(AiTriggerKind::SCHEDULED_JOB, $hotel, agent: 'insights', callback: function () use ($hotel) {
        AiCostContext::for(AiTriggerKind::GUEST_MESSAGE, $hotel, callback: fn () => null);
        app(AiCostRecorder::class)->record(agent: 'InsightsAgent', provider: 'openai', model: 'gpt-5.4', operation: AiOperation::CHAT, inputTokens: 10, outputTokens: 5);
    });

    $run = runRows()->sole();

    expect($run->agent->value)->toBe('insights')
        ->and($run->usage)->toBe(['requests' => 1, 'input_tokens' => 10, 'output_tokens' => 5, 'cached_tokens' => 0]);
});

it('records a run stopped by the spend ceiling as refused, with no usage', function () {
    $hotel = cmaHotel();
    $this->mock(AiSpendCeiling::class, fn ($mock) => $mock->shouldReceive('assertNotExceeded')->andThrow(new AiSpendCeilingExceededException($hotel->hotelGroup, 50.0, 50.0)));

    expect(fn () => AiCostContext::for(AiTriggerKind::SCHEDULED_JOB, $hotel, agent: 'guest_memory', callback: fn () => 'never'))
        ->toThrow(AiSpendCeilingExceededException::class);

    $run = runRows()->sole();

    expect($run->result->value)->toBe('refused')
        ->and($run->reason)->toBe('spend_ceiling')
        ->and($run->agent->value)->toBe('guest_memory')
        ->and($run->usage)->toBeNull();
});

it('records a run that threw as failed', function () {
    $hotel = cmaHotel();

    expect(fn () => AiCostContext::for(AiTriggerKind::SCHEDULED_JOB, $hotel, agent: 'insights', callback: fn () => throw new RuntimeException('provider down')))
        ->toThrow(RuntimeException::class);

    expect(runRows()->sole())
        ->result->value->toBe('failed')
        ->reason->toBe('RuntimeException');
});

it('names an advisor chat run admin_advisor, with its conversation', function () {
    $s = aatSeed();
    AdminAdvisorAgent::fake(['There are 3 arrivals today.']);

    $response = aatChat($this, $s, ['message' => 'How many arrivals today?'])->assertOk();

    $run = runRows()->sole();

    expect($run->agent->value)->toBe('admin_advisor')
        ->and($run->trigger_kind)->toBe(AiTriggerKind::STAFF_REQUEST)
        ->and($run->acted_for_id)->toBe($s['admin']->id)
        ->and($run->conversation_id)->toBe($response->json('body.conversation_id'));
});

it('records a call that names no agent as unattributed, and reads it back', function () {
    $hotel = cmaHotel();

    AiCostContext::for(AiTriggerKind::SCHEDULED_JOB, $hotel, callback: fn () => null);

    expect(runRows()->sole()->agent->value)->toBe('unattributed');
});
