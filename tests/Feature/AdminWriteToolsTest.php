<?php

use App\Ai\Tools\Admin\AdminToolset;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\Booking;
use App\Models\KnowledgeBaseArticle;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Task;
use App\Models\User;
use App\Services\BookingCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * SPEC-055 User Stories 2, 3 and 6 (writes): the Admin AI changes records
 * through the same operations as the staff screens, all or nothing, never
 * overriding a safety check, never duplicating a record it can recognise,
 * and always saying what changed (FR-009–FR-015, FR-022).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Http::fake();
    Queue::fake();
});

/**
 * A successful write says what changed and which records (FR-015).
 */
function aatExpectDone(mixed $result): array
{
    expect($result)->toBeArray()
        ->and($result['ok'] ?? null)->toBeTrue()
        ->and($result['ids'] ?? [])->not->toBeEmpty()
        ->and($result['changed'] ?? [])->not->toBeEmpty();

    return $result;
}

it('assigns rooms to a two-room reservation in one call, then changes and removes one', function () {
    $s = aatSeed();
    $reservation = aatReservation($s['hotel'], $s['type'], [null, null]);
    $tool = aatTool($s['admin'], 'AssignRoomsTool');

    aatExpectDone(aatCall($tool, ['code' => $reservation->reservation_id, 'assignments' => [['room_number' => '101'], ['room_number' => '102']]]));

    $rooms = fn () => $reservation->reservationRooms()->active()->with('room')->get()->map(fn (ReservationRoom $line) => $line->room?->room_number)->sort()->values()->all();

    expect($rooms())->toBe(['101', '102']);

    $line101 = $reservation->reservationRooms()->whereHas('room', fn ($room) => $room->where('room_number', '101'))->first();

    aatExpectDone(aatCall($tool, ['code' => $reservation->reservation_id, 'assignments' => [['line_id' => $line101->id, 'room_number' => '103']]]));
    expect($rooms())->toBe(['102', '103']);

    aatExpectDone(aatCall($tool, ['code' => $reservation->reservation_id, 'assignments' => [['line_id' => $line101->id, 'room_number' => null]]]));
    expect($rooms())->toBe([null, '102']);
});

it('assigns all the rooms in a call or none of them (FR-013)', function () {
    $s = aatSeed();
    avRooms($s['hotel'], $s['type'], 2);
    aatRoom($s['hotel'], avType($s['hotel'], 'Suite'), '501');
    $reservation = aatReservation($s['hotel'], $s['type'], [null, null]);

    // The first assignment is fine; the second breaks a rule.
    $result = aatCall(aatTool($s['admin'], 'AssignRoomsTool'), [
        'code' => $reservation->reservation_id,
        'assignments' => [['room_number' => '101'], ['room_number' => '501']],
    ]);

    expect($result)->toContain("Room 501 is not of the line's room type")
        ->and($result)->toContain('Nothing was changed')
        ->and($reservation->reservationRooms()->whereNotNull('room_id')->count())->toBe(0);
});

it('refuses a room of another type with the assignment rule\'s own words', function () {
    $s = aatSeed();
    $suite = avType($s['hotel'], 'Suite');
    aatRoom($s['hotel'], $suite, '501');

    $result = aatCall(aatTool($s['admin'], 'AssignRoomsTool'), ['code' => $s['code'], 'assignments' => [['room_number' => '501']]]);

    expect($result)->toContain("Room 501 is not of the line's room type")
        ->and($s['reservation']->reservationRooms()->whereNotNull('room_id')->count())->toBe(0);
});

it('changes a reservation\'s dates and party but has no way to change its status (FR-012)', function () {
    $s = aatSeed();
    $tz = $s['hotel']->timezone;
    $tool = aatTool($s['admin'], 'UpdateReservationTool');

    expect(array_keys($tool->schema(new JsonSchemaTypeFactory)))->not->toContain('status');

    aatExpectDone(aatCall($tool, [
        'code' => $s['code'],
        'departure_date' => now($tz)->addDays(3)->toDateString(),
        'adults' => 2,
        'status' => 'checked_in',
    ]));

    $reservation = $s['reservation']->fresh();

    expect($reservation->departure_date->toDateString())->toBe(now($tz)->addDays(3)->toDateString())
        ->and($reservation->adults)->toBe(2)
        ->and($reservation->status)->toBe(ReservationStatus::CONFIRMED);
});

it('refuses a change that would oversell a room type, and cannot override it', function () {
    $s = aatSeed();

    $result = aatCall(aatTool($s['admin'], 'UpdateReservationTool'), [
        'code' => $s['code'],
        'rooms' => [['room_type' => 'Deluxe', 'quantity' => 5]],
    ]);

    expect($result)->toBeString()
        ->and($result)->toStartWith('Not done:')
        ->and($s['reservation']->reservationRooms()->active()->count())->toBe(1);
});

it('cancels a reservation without deleting it', function () {
    $s = aatSeed();

    aatExpectDone(aatCall(aatTool($s['admin'], 'CancelReservationTool'), ['code' => $s['code']]));

    $reservation = Reservation::withoutGlobalScope('hotel')->withTrashed()->find($s['reservation']->id);

    expect($reservation->status)->toBe(ReservationStatus::CANCELLED)
        ->and($reservation->trashed())->toBeFalse();
});

it('reports a reservation code already on file instead of booking it twice (R9)', function () {
    $s = aatSeed();
    $args = [...aatArgs('CreateReservationTool', $s), 'reservation_code' => $s['code']];

    $result = aatCall(aatTool($s['admin'], 'CreateReservationTool'), $args);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Already exists')
        ->and(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $s['hotel']->id)->count())->toBe(1);
});

it('updates only the guest fields the admin gave', function () {
    $s = aatSeed();
    $s['guest']->update(['nationality' => 'GB']);

    aatExpectDone(aatCall(aatTool($s['admin'], 'UpdateGuestTool'), ['guest_id' => $s['guest']->id, 'is_vip' => true]));

    expect($s['guest']->fresh())->is_vip->toBeTrue()->nationality->toBe('GB');
});

it('changes only the housekeeping status of an occupied room', function () {
    $s = aatSeed();
    $s['rooms']['101']->update(['status' => 'occupied', 'housekeeping_status' => 'cleaning']);

    aatExpectDone(aatCall(aatTool($s['admin'], 'SetHousekeepingStatusTool'), ['room_number' => '101', 'housekeeping_status' => 'clean']));

    $room = $s['rooms']['101']->fresh();

    expect($room->housekeeping_status->value)->toBe('clean')
        ->and($room->status->value)->toBe('occupied');
});

it('takes a room out of order, updates it and returns it to service', function () {
    $s = aatSeed();

    aatExpectDone(aatCall(aatTool($s['admin'], 'SetRoomOutOfOrderTool'), ['room_number' => '103', 'reason' => 'AC broken', 'expected_end_date' => now()->addDays(2)->toDateString()]));
    expect($s['rooms']['103']->fresh()->status->value)->toBe('out_of_order');

    aatExpectDone(aatCall(aatTool($s['admin'], 'UpdateOutOfOrderTool'), ['room_number' => '103', 'reason' => 'AC compressor']));
    expect($s['rooms']['103']->fresh()->out_of_order_reason)->toBe('AC compressor');

    expect(aatCall(aatTool($s['admin'], 'UpdateOutOfOrderTool'), ['room_number' => '103', 'expected_end_date' => '2001-01-01']))
        ->toContain('cannot be in the past');

    aatExpectDone(aatCall(aatTool($s['admin'], 'ReturnRoomToServiceTool'), ['room_number' => '103']));
    expect($s['rooms']['103']->fresh()->status->value)->toBe('available');
});

it('asks which person is meant instead of picking one, and changes nothing', function () {
    $s = aatSeed();
    User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $s['hotel']->id, 'name' => 'Omar Said']);
    User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $s['hotel']->id, 'name' => 'Omar Khaled']);
    $tool = aatTool($s['admin'], 'UpdateTaskTool');

    $two = aatCall($tool, ['task_id' => $s['task']->id, 'assignee' => 'Omar']);
    $none = aatCall($tool, ['task_id' => $s['task']->id, 'assignee' => 'Zainab']);

    expect($two['ambiguous'])->toBe('staff member')
        ->and($two['candidates'])->toHaveCount(2)
        ->and($none)->toContain('Nobody in this hotel matches')
        ->and($s['task']->fresh()->assigned_to_user_id)->toBeNull();

    $done = aatExpectDone(aatCall($tool, ['task_id' => $s['task']->id, 'assignee' => 'Omar Said', 'priority' => 'high']));

    expect($done['changed']['priority'])->toBe('high')
        ->and($s['task']->fresh()->assignedToUser->name)->toBe('Omar Said');
});

it('reports the same open task instead of creating it twice (R9)', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'CreateTaskTool');

    aatExpectDone(aatCall($tool, ['title' => 'Fix lamp', 'room_number' => '102']));
    $again = aatCall($tool, ['title' => 'fix lamp', 'room_number' => '102']);

    expect($again['ok'])->toBeFalse()
        ->and($again['message'])->toContain('Already exists')
        ->and(Task::withoutGlobalScope('hotel')->where('title', 'ilike', 'fix lamp')->count())->toBe(1);
});

it('books an activity, refuses a second identical booking, and never books past capacity', function () {
    $s = aatSeed();
    $s['activity']->update(['daily_capacity' => 2]);
    $date = now($s['hotel']->timezone)->addDays(4)->toDateString();
    $tool = aatTool($s['admin'], 'CreateActivityBookingTool');

    aatExpectDone(aatCall($tool, ['guest' => $s['guest']->id, 'activity' => $s['activity']->name, 'scheduled_for' => $date, 'pax' => 1]));

    $again = aatCall($tool, ['guest' => $s['guest']->id, 'activity' => $s['activity']->name, 'scheduled_for' => $date]);
    expect($again['ok'])->toBeFalse()->and($again['message'])->toContain('Already exists');

    $other = aatGuest($s['hotel']);
    $full = aatCall($tool, ['guest' => $other->id, 'activity' => $s['activity']->name, 'scheduled_for' => $date, 'pax' => 2]);

    expect($full)->toBeString()
        ->and($full)->toStartWith('Not done:')
        ->and(Booking::withoutGlobalScope('hotel')->where('guest_id', $other->id)->count())->toBe(0);
});

it('moves a booking through its lifecycle and answers a guest\'s cancellation request', function () {
    $s = aatSeed();
    $booking = $s['booking'];

    aatExpectDone(aatCall(aatTool($s['admin'], 'UpdateBookingStatusTool'), ['booking' => $booking->reference, 'status' => 'confirmed']));
    expect($booking->fresh()->status->value)->toBe('confirmed');

    expect(aatCall(aatTool($s['admin'], 'DecideBookingCancellationTool'), ['booking' => $booking->reference, 'decision' => 'approve']))
        ->toContain('no open cancellation request');

    app(BookingCancellationService::class)->request($booking->fresh(), $s['guest'], 'Plans changed');

    aatExpectDone(aatCall(aatTool($s['admin'], 'DecideBookingCancellationTool'), ['booking' => $booking->reference, 'decision' => 'approve']));
    expect($booking->fresh()->status->value)->toBe('cancelled');
});

it('saves the admin\'s own text as a hotel article, published and sent for indexing', function () {
    $s = aatSeed();

    aatExpectDone(aatCall(aatTool($s['admin'], 'CreateKnowledgeArticleTool'), [
        'title' => 'Old town shuttle',
        'content' => 'The shuttle to the old town leaves at 9:00 and 15:00 from the main gate.',
    ]));

    $article = KnowledgeBaseArticle::withoutGlobalScope('hotel')->where('title', 'Old town shuttle')->firstOrFail();

    expect($article->hotel_id)->toBe($s['hotel']->id)
        ->and($article->content)->toBe('The shuttle to the old town leaves at 9:00 and 15:00 from the main gate.')
        ->and($article->status)->toBe('published');

    Queue::assertPushed(SyncKnowledgeChunksJob::class);
});

it('cannot change a global article, and has no tool for policies, documents or global knowledge', function () {
    $s = aatSeed();
    $global = KnowledgeBaseArticle::withoutGlobalScope('hotel')->create(['hotel_id' => null, 'title' => 'Global', 'content' => 'Shared.', 'status' => 'draft']);

    expect(aatCall(aatTool($s['admin'], 'UpdateKnowledgeArticleTool'), ['article_id' => $global->id, 'title' => 'Mine now']))
        ->toContain('has no knowledge base article')
        ->and($global->fresh()->title)->toBe('Global');

    $names = collect(AdminToolset::for($s['admin']))->map->name();

    expect($names->filter(fn (string $name) => preg_match('/Polic|Document|Global/', $name))->all())->toBe([]);
});
