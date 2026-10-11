<?php

use App\Ai\Tools\AuditedTool;
use App\Ai\Tools\Contracts\WritesData;
use App\Enums\AiAgentName;
use App\Enums\Permission;
use App\Models\AiActivity;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-083 R3: one data_change row per AI write action, whatever the
 * outcome — succeeded, refused, or failed and rolled back.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

/**
 * A write tool whose behaviour each test chooses.
 */
function recorderTool(Hotel $hotel, Closure $behaviour): Tool
{
    return new class($hotel, $behaviour) implements Tool, WritesData
    {
        public function __construct(private Hotel $hotel, private Closure $behaviour) {}

        public function description(): string
        {
            return 'test';
        }

        public function handle(Request $request): string
        {
            return ($this->behaviour)($this->hotel, $request);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };
}

function recorderGuest(Hotel $hotel, string $name): Guest
{
    return Guest::create(['hotel_id' => $hotel->id, 'first_name' => $name, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
}

it('records a succeeded write with its target, agent, tool, run and the person acted for', function () {
    $hotel = cmaHotel();
    $guest = gsGuest($hotel);
    $tool = new AuditedTool(recorderTool($hotel, function (Hotel $hotel) {
        recorderGuest($hotel, 'Made');

        return 'Guest created.';
    }), AiAgentName::CONCIERGE, $guest);

    $tool->handle(new Request([], 'call-9'));

    $made = Guest::withoutGlobalScope('hotel')->where('first_name', 'Made')->firstOrFail();
    $row = AiActivity::withoutGlobalScope('hotel')->sole();

    expect($row->category->value)->toBe('data_change')
        ->and($row->result->value)->toBe('succeeded')
        ->and($row->agent->value)->toBe('concierge')
        ->and($row->tool)->toBe('anonymous')
        ->and($row->action)->toBe('guest.created')
        ->and($row->target_id)->toBe($made->id)
        ->and($row->changes_count)->toBe(1)
        ->and($row->acted_for_id)->toBe($guest->id)
        ->and($row->hotel_id)->toBe($hotel->id)
        ->and($row->reason)->toBeNull();

    $event = EventLog::withoutGlobalScope('hotel')->where('subject_id', $made->id)->sole();
    expect($event->actor_id)->toBe($guest->id)
        ->and($event->context['ai']['agent'])->toBe('concierge')
        ->and($event->context['ai']['tool_call_id'])->toBe('call-9');
});

it('records a refusal when the tool changed nothing, with its message as the reason', function () {
    $hotel = cmaHotel();
    $message = str_repeat('The activity is full on that date. No booking was made. ', 10);
    $tool = new AuditedTool(recorderTool($hotel, fn () => $message), AiAgentName::CONCIERGE, gsGuest($hotel));

    $tool->handle(new Request([]));

    $row = AiActivity::withoutGlobalScope('hotel')->sole();

    expect($row->result->value)->toBe('refused')
        ->and($row->target_id)->toBeNull()
        ->and($row->changes_count)->toBe(0)
        ->and(mb_strlen($row->reason))->toBeLessThanOrEqual(200)
        ->and($row->reason)->toStartWith('The activity is full on that date.');
});

it('rolls a failed write back, records it after the rollback, and rethrows', function () {
    $hotel = cmaHotel();
    $tool = new AuditedTool(recorderTool($hotel, function (Hotel $hotel) {
        recorderGuest($hotel, 'Ghost');

        throw new DomainException('boom');
    }), AiAgentName::CONCIERGE, gsGuest($hotel));

    expect(fn () => $tool->handle(new Request([])))->toThrow(DomainException::class, 'boom');

    $row = AiActivity::withoutGlobalScope('hotel')->sole();

    expect($row->result->value)->toBe('failed')
        ->and($row->reason)->toBe('DomainException')
        ->and(Guest::withoutGlobalScope('hotel')->where('first_name', 'Ghost')->exists())->toBeFalse()
        ->and(EventLog::withoutGlobalScope('hotel')->where('event_type', 'guest.created')->where('changes->first_name->to', 'Ghost')->exists())->toBeFalse();
});

it('records a permission refusal by the Admin AI guard without running the tool', function () {
    $s = aatSeed();
    $employee = aatEmployee($s['hotel'], [Permission::GUESTS_VIEW]);

    $result = aatCall(aatTool($employee, 'CreateGuestTool', 'conv-7'), ['phone_number' => '+44 7700 900555', 'first_name' => 'Nope']);

    $row = AiActivity::withoutGlobalScope('hotel')->sole();

    expect($result)->toContain('You do not have permission')
        ->and(Guest::withoutGlobalScope('hotel')->where('first_name', 'Nope')->exists())->toBeFalse()
        ->and($row->result->value)->toBe('refused')
        ->and($row->reason)->toBe('permission')
        ->and($row->agent->value)->toBe('admin_advisor')
        ->and($row->tool)->toBe('CreateGuestTool')
        ->and($row->conversation_id)->toBe('conv-7')
        ->and($row->acted_for_id)->toBe($employee->id);
});

it('never stores what was said in the request', function () {
    $hotel = cmaHotel();
    $tool = new AuditedTool(recorderTool($hotel, function (Hotel $hotel, Request $request) {
        recorderGuest($hotel, 'Quiet');

        return 'Done.';
    }), AiAgentName::CONCIERGE, gsGuest($hotel));

    $tool->handle(new Request(['description' => 'My private note about my health']));

    expect(json_encode(AiActivity::withoutGlobalScope('hotel')->sole()->getAttributes()))->not->toContain('private note');
});
