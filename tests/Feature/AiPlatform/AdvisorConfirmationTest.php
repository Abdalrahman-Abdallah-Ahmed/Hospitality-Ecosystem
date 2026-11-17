<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Enums\InboundMessageStatus;
use App\Enums\ReservationStatus;
use App\Enums\SenderType;
use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Models\WhatsAppInboundMessage;
use App\Services\Ai\AdvisorTurn;
use App\Services\Metering\MeteringService;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;

/*
 * SPEC-055 FR-017, SC-009: hard-to-reverse actions wait for the admin's
 * confirmation. The tool pauses before it runs; only the admin's very next
 * message, within 10 minutes, can confirm it; anything else leaves it undone;
 * a confirmation is used once; at most 10 actions wait for one "yes".
 *
 * With a faked model, laravel/ai records the decision but does not run the
 * paused tool itself, so these tests check which decision the advisor sends
 * and what the admin is told. The tools' own effects are covered by
 * AdminWriteToolsTest and AdminToolParityTest.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

/**
 * Ask the advisor to cancel the seeded reservation; the fake model calls the
 * cancel tool, which pauses for confirmation.
 */
function aatPauseForCancel($test, array $seed, array $then = ['Done.'], string $message = 'Cancel it')
{
    AdminAdvisorAgent::fake([new ToolCall('call_1', 'CancelReservationTool', ['code' => $seed['code']]), ...$then]);

    return aatChat($test, $seed, ['message' => $message.' '.$seed['code']]);
}

function aatDecided(string $action, ?string $resultContains = null): Closure
{
    return function (AgentPrompt $prompt) use ($action, $resultContains) {
        $decision = $prompt->approvalDecisions?->get('*');

        return $decision !== null
            && $decision->action === $action
            && ($resultContains === null || str_contains((string) $decision->result, $resultContains));
    };
}

it('pauses a cancellation with a summary written from the records, and changes nothing yet', function () {
    $s = aatSeed();
    $this->freezeSecond();

    $response = aatPauseForCancel($this, $s)->assertOk();

    expect($response->json('body.pending_confirmation.ids'))->toBe(['call_1'])
        ->and($response->json('body.pending_confirmation.items.0.summary'))
        ->toBe("Cancel reservation {$s['code']} for Alpha Alphason: 1 room(s) (Deluxe unassigned), arriving {$s['reservation']->arrival_date->toDateString()}.")
        ->and($response->json('body.reply'))->toContain('Reply YES to confirm or NO to cancel')
        ->and($response->json('body.pending_confirmation.expires_at'))->toBe(now()->addMinutes(10)->toIso8601String())
        ->and($s['reservation']->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('runs the paused action when the admin confirms within 10 minutes', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');
    $this->travel(9)->minutes();

    $reply = aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'confirm'])->assertOk();

    AdminAdvisorAgent::assertPrompted(aatDecided('approve'));
    expect($reply->json('body.pending_confirmation'))->toBeNull();
});

it('accepts a plain "yes", in English or Arabic, as the confirmation', function (string $yes) {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');

    aatChat($this, $s, ['conversation_id' => $conversation, 'message' => $yes])->assertOk();

    AdminAdvisorAgent::assertPrompted(aatDecided('approve'));
})->with(['yes', 'YES!', 'Go ahead.', 'نعم', 'موافق']);

it('does nothing when the confirmation comes after 10 minutes, and says it expired', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');
    $this->travel(11)->minutes();

    aatChat($this, $s, ['conversation_id' => $conversation, 'message' => 'yes'])->assertOk();

    AdminAdvisorAgent::assertPrompted(aatDecided('reject', 'after the 10-minute window'));
    AdminAdvisorAgent::assertNotPrompted(aatDecided('approve'));
});

it('does nothing when the admin declines', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');

    aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'decline'])->assertOk();

    AdminAdvisorAgent::assertPrompted(aatDecided('reject', 'declined'));
    AdminAdvisorAgent::assertNotPrompted(aatDecided('approve'));
});

it('drops the waiting action when the admin says anything else, so a later "yes" confirms nothing', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s, ['Occupancy is 40%.', 'Yes to what?'])->json('body.conversation_id');

    aatChat($this, $s, ['conversation_id' => $conversation, 'message' => "What's occupancy today?"])->assertOk();
    aatChat($this, $s, ['conversation_id' => $conversation, 'message' => 'yes'])->assertOk();

    AdminAdvisorAgent::assertNotPrompted(aatDecided('approve'));
    AdminAdvisorAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'yes' && $prompt->approvalDecisions === null);
});

it('does not take "yes, but change the date" as a confirmation', function () {
    expect(AdvisorTurn::classify('yes but change the date'))->toBeNull()
        ->and(AdvisorTurn::classify('Yes.'))->toBe('confirm')
        ->and(AdvisorTurn::classify("don't"))->toBe('decline')
        ->and(AdvisorTurn::classify('لا'))->toBe('decline');
});

it('uses a confirmation once: confirming again finds nothing waiting', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');

    aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'confirm'])->assertOk();

    aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'confirm'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'There is nothing waiting for confirmation.')
        ->assertJsonMissingPath('errors');
});

it('refuses a confirmation of a list the admin did not see', function () {
    $s = aatSeed();
    $conversation = aatPauseForCancel($this, $s)->json('body.conversation_id');

    aatChat($this, $s, ['conversation_id' => $conversation, 'decision' => 'confirm', 'pending_ids' => ['call_1', 'call_2']])
        ->assertStatus(409)
        ->assertJsonPath('body.pending_confirmation.ids', ['call_1']);

    // The key is always present on this 409 (the frontend relies on it).

    AdminAdvisorAgent::assertNotPrompted(aatDecided('approve'));
});

it('writes the confirmation in Arabic when the admin writes in Arabic', function () {
    $s = aatSeed();

    $response = aatPauseForCancel($this, $s, ['تم.'], 'ألغِ الحجز')->assertOk();

    expect($response->json('body.pending_confirmation.locale'))->toBe('ar')
        ->and($response->json('body.reply'))->toContain('يرجى التأكيد')
        ->and($response->json('body.pending_confirmation.items.0.summary'))->toContain("إلغاء الحجز {$s['code']}");
});

it('refuses more than 10 actions at once and has the advisor ask again in batches', function () {
    $s = aatSeed();
    $many = fn () => AgentResponse::fakeWithPendingApprovals(collect(range(1, 12))->map(fn (int $i) => new PendingApproval("call_{$i}", 'CheckOutTool', [], "Check out reservation R{$i}.")));
    $batch = AgentResponse::fakeWithPendingApprovals(collect(range(1, 10))->map(fn (int $i) => new PendingApproval("call_b{$i}", 'CheckOutTool', [], "Check out reservation B{$i}.")));

    // Too many, then a batch of 10.
    AdminAdvisorAgent::fake([$many(), $batch]);

    $response = aatChat($this, $s, ['message' => 'Check out all departures'])->assertOk();

    AdminAdvisorAgent::assertPrompted(aatDecided('reject', 'at most 10'));
    expect($response->json('body.pending_confirmation.ids'))->toHaveCount(10);

    // Too many three times in a row: the turn stops with a fixed answer.
    AdminAdvisorAgent::fake([$many(), $many(), $many(), 'unused']);

    $stopped = aatChat($this, $s, ['message' => 'Check out everyone'])->assertOk();

    expect($stopped->json('body.reply'))->toContain('at most 10')
        ->and($stopped->json('body.pending_confirmation'))->toBeNull();
});

it('asks for and takes the confirmation over WhatsApp, with "YES" as the next message', function () {
    $s = aatSeed();
    $s['admin']->update(['phone' => '+201000000777']);
    $sent = [];

    $this->mock(WhatsAppMessageService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('send')->andReturnUsing(function (string $to, string $text) use (&$sent) {
            $sent[] = $text;
        });
    });

    AdminAdvisorAgent::fake([new ToolCall('call_wa', 'CancelReservationTool', ['code' => $s['code']]), 'Cancelled.']);

    $job = fn (string $text) => (new ProcessInboundWhatsAppMessageJob(
        inbound: WhatsAppInboundMessage::create(['phone_number' => '+201000000777', 'status' => InboundMessageStatus::RECEIVED]),
        phoneNumber: '+201000000777',
        messageText: $text,
        senderType: SenderType::ADMIN,
        sender: $s['admin'],
        hotel: $s['hotel'],
        reservation: null,
        devicePaired: true,
    ))->handle(app(WhatsAppMessageService::class), app(MeteringService::class));

    $job("Cancel {$s['code']}");

    expect($sent[0] ?? '')->toContain("Cancel reservation {$s['code']}")
        ->and($sent[0] ?? '')->toContain('Reply YES');

    $job('YES');

    AdminAdvisorAgent::assertPrompted(aatDecided('approve'));
    expect($sent[1] ?? '')->toBe('Cancelled.');
});
