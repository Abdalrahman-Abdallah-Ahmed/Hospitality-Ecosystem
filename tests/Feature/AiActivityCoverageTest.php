<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Agents\RecommendationAgent;
use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\AuditedTool;
use App\Ai\Tools\Contracts\ReadsData;
use App\Ai\Tools\Contracts\WritesData;
use App\Ai\Tools\PitchActivityTool;
use App\Models\AiActivity;
use App\Services\BookingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

/*
 * SPEC-083 FR-026, SC-003: every AI write action is audited. The checks read
 * the tool classes and the agents' real tool lists, so a write tool added
 * without a marker, or reaching a model without the audit wrapper, fails
 * here without anyone editing this file.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

/**
 * @return list<class-string>
 */
function aiToolClasses(): array
{
    return collect(File::files(app_path('Ai/Tools')))
        ->map(fn ($file) => 'App\\Ai\\Tools\\'.$file->getFilenameWithoutExtension())
        ->reject(fn (string $class) => $class === AuditedTool::class)
        ->filter(fn (string $class) => class_exists($class))
        ->values()
        ->all();
}

/**
 * The tools every agent hands its model, for a guest who is in-house with an
 * approved recommendation and a pitch-eligible turn.
 *
 * @return array{concierge: GuestConciergeAgent, recommendation: RecommendationAgent, admin: list<GuardedTool>, guest: mixed, reservation: mixed, hotel: mixed, recommendationRow: mixed}
 */
function aiAgentsUnderTest(): array
{
    rapPitchingOn();
    $hotel = cmaHotel();
    [$guest, $reservation] = rapInHouseStay($hotel);
    $recommendation = rapRecommendation($reservation, rapActivity($hotel, 'Snorkeling'), 'approved');

    $concierge = GuestConciergeAgent::make(guest: $guest, hotel: $hotel, reservation: $reservation);
    $concierge->pitchTurn = rapTurn($guest, $reservation);

    return [
        'concierge' => $concierge,
        'recommendation' => RecommendationAgent::make(hotel: $hotel, reservation: $reservation),
        'admin' => AdminToolset::for(cmaAdmin($hotel)),
        'guest' => $guest,
        'reservation' => $reservation,
        'hotel' => $hotel,
        'recommendationRow' => $recommendation,
    ];
}

it('marks every AI tool as reading or writing, never both or neither', function () {
    foreach (aiToolClasses() as $class) {
        $reads = is_subclass_of($class, ReadsData::class);
        $writes = is_subclass_of($class, WritesData::class);

        expect($reads xor $writes)->toBeTrue("{$class} must implement exactly one of ReadsData / WritesData");
    }
});

it('hands no agent a write tool without the audit wrapper', function () {
    $agents = aiAgentsUnderTest();

    $tools = collect([...$agents['concierge']->tools(), ...$agents['recommendation']->tools(), ...$agents['admin']]);

    expect($tools->contains(fn ($tool) => $tool instanceof AuditedTool && $tool->inner() instanceof PitchActivityTool))->toBeTrue('the pitch tool must be offered, wrapped');

    foreach ($tools as $tool) {
        $inner = $tool instanceof GuardedTool || $tool instanceof AuditedTool ? $tool->inner() : $tool;

        if ($inner instanceof WritesData) {
            expect($tool instanceof GuardedTool || $tool instanceof AuditedTool)
                ->toBeTrue($inner::class.' reaches the model without the audit wrapper');
        }
    }
});

it('writes one complete data_change row for every Concierge and Recommendation write tool', function (string $name, Closure $args) {
    $agents = aiAgentsUnderTest();
    $tools = collect([...$agents['concierge']->tools(), ...$agents['recommendation']->tools()]);
    $tool = $tools->first(fn ($tool) => $tool instanceof AuditedTool && class_basename($tool->inner()) === $name);

    expect($tool)->not->toBeNull("{$name} is not offered wrapped");

    $result = (string) $tool->handle(new Request($args($agents), 'call-1'));

    $row = AiActivity::withoutGlobalScope('hotel')->where('category', 'data_change')->sole();

    expect($row->result->value)->toBe('succeeded', "{$name}: {$result}")
        ->and($row->tool)->toBe($name)
        ->and($row->agent->value)->toBeIn(['concierge', 'recommendation'])
        ->and($row->action)->not->toBeEmpty()
        ->and($row->target_type)->not->toBeNull()
        ->and($row->target_id)->not->toBeNull()
        ->and($row->hotel_id)->toBe($agents['hotel']->id)
        ->and($row->changes_count)->toBeGreaterThan(0)
        ->and($row->occurred_at)->not->toBeNull();

    if ($row->agent->value === 'concierge') {
        expect($row->acted_for_id)->toBe($agents['guest']->id);
    }
})->with([
    'CreateBookingTool' => ['CreateBookingTool', fn (array $a) => [
        'activity_id' => $a['recommendationRow']->activity_id,
        'scheduled_for' => now($a['hotel']->timezone)->addDay()->toDateString(),
    ]],
    'RequestBookingCancellationTool' => ['RequestBookingCancellationTool', function (array $a) {
        $booking = abBook($a['hotel'], $a['recommendationRow']->activity, now($a['hotel']->timezone)->addDays(2)->toDateString(), 1, [
            'guest_id' => $a['guest']->id,
            'reservation_id' => $a['reservation']->id,
        ]);

        return ['booking_id' => $booking->id, 'reason' => 'Plans changed'];
    }],
    'UpdateRecommendationTool' => ['UpdateRecommendationTool', function (array $a) {
        $a['recommendationRow']->forceFill(['status' => 'sent', 'delivered_at' => now()])->saveQuietly();

        return ['recommendation_id' => $a['recommendationRow']->id, 'action' => 'accepted'];
    }],
    'CreateGuestServiceRequestTool' => ['CreateGuestServiceRequestTool', fn () => ['title' => 'Extra towels', 'description' => 'Two more towels']],
    'RequestRoomChangeTool' => ['RequestRoomChangeTool', fn () => ['reason' => 'Too noisy', 'preference' => 'higher floor']],
    'EscalateToHumanTool' => ['EscalateToHumanTool', fn () => ['reason' => 'Wants to talk to a person']],
    'SetContactPreferenceTool' => ['SetContactPreferenceTool', fn () => ['preference' => 'opt_out', 'guest_words' => 'stop messaging me']],
    'PitchActivityTool' => ['PitchActivityTool', fn () => ['guest_words' => 'this evening']],
    'CreateRecommendationTool' => ['CreateRecommendationTool', fn (array $a) => [
        'activity_id' => rapActivity($a['hotel'], 'Kayaking')->id,
        'reason' => 'Likes the sea',
        'predicted_confidence' => 0.7,
    ]],
]);

/**
 * aatArgs() are valid arguments; a few tools also need the record in the
 * right state for the call to change something.
 *
 * @param  array<string, mixed>  $s
 * @return array<string, mixed>
 */
function coverageArgs(string $name, array $s): array
{
    $assign = ['reservation_id' => $s['code'], 'assign' => [['room_type' => $s['type']->name, 'room_number' => '101']]];

    return match ($name) {
        'CheckInTool' => $assign,
        'CheckOutTool' => (function () use ($s, $assign) {
            aatCall(aatTool($s['admin'], 'CheckInTool'), $assign);

            return ['reservation_id' => $s['code']];
        })(),
        'ReportTaskIssueTool' => (function () use ($name, $s) {
            $s['task']->forceFill(['housekeeping_kind' => 'cleaning'])->saveQuietly();

            return aatArgs($name, $s);
        })(),
        'DecideBookingCancellationTool' => (function () use ($name, $s) {
            app(BookingCancellationService::class)->request($s['booking']->fresh(), $s['guest'], 'Plans changed');

            return aatArgs($name, $s);
        })(),
        'UpdateOutOfOrderTool', 'ReturnRoomToServiceTool' => (function () use ($name, $s) {
            aatCall(aatTool($s['admin'], 'SetRoomOutOfOrderTool'), aatArgs('SetRoomOutOfOrderTool', $s));

            return aatArgs($name, $s);
        })(),
        default => aatArgs($name, $s),
    };
}

it('writes one complete data_change row for every Admin AI write tool', function () {
    $names = collect(AdminToolset::for(aatSeed('Registry')['admin']))
        ->filter(fn (GuardedTool $tool) => $tool->kind() === GuardedTool::WRITE)
        ->map(fn (GuardedTool $tool) => $tool->name())
        ->values();

    expect($names)->not->toBeEmpty();

    // Each tool on a hotel of its own: an earlier tool (a cancellation, a
    // check-in) would otherwise leave nothing for a later one to change.
    foreach ($names as $i => $name) {
        $s = aatSeed('Tool'.$i);
        $args = coverageArgs($name, $s);
        $before = AiActivity::withoutGlobalScope('hotel')->count();

        $result = aatCall(aatTool($s['admin'], $name, 'conv-cover'), $args, 'call-'.$name);

        $rows = AiActivity::withoutGlobalScope('hotel')->where('tool', $name)->where('hotel_id', $s['hotel']->id)->get();

        expect(AiActivity::withoutGlobalScope('hotel')->count() - $before)->toBe(1, "{$name} wrote no row, or more than one")
            ->and($rows)->toHaveCount(1)
            ->and($rows[0]->category->value)->toBe('data_change')
            ->and($rows[0]->result->value)->toBe('succeeded', $name.': '.json_encode($result))
            ->and($rows[0]->agent->value)->toBe('admin_advisor')
            ->and($rows[0]->conversation_id)->toBe('conv-cover')
            ->and($rows[0]->acted_for_id)->toBe($s['admin']->id)
            ->and($rows[0]->target_id)->not->toBeNull();
    }
});
