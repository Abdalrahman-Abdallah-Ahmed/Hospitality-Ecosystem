<?php

use App\Ai\Tools\CreateBookingTool;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\EscalateToHumanTool;
use App\Ai\Tools\GetActivitiesTool;
use App\Ai\Tools\GetOwnReservationTool;
use App\Ai\Tools\RequestRoomChangeTool;
use App\Enums\ActorKind;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

/**
 * Two in-house guests of one hotel: A in room 214, B in room 301, and an
 * open maintenance request of B's.
 */
function gsTwoGuests(): array
{
    [, $hotel] = gsHotel();
    $a = gsGuest($hotel, ['first_name' => 'Amira']);
    $b = gsGuest($hotel, ['first_name' => 'Bilal']);
    $aReservation = gsInHouse($hotel, $a, ['214']);
    $bReservation = gsInHouse($hotel, $b, ['301']);
    gsRunTool(new CreateGuestServiceRequestTool($b, $hotel, $bReservation), ['kind' => 'maintenance_request', 'title' => 'Sink leaking', 'description' => 'Bilal sink']);

    return [$hotel, $a, $aReservation, $b, $bReservation, Task::withoutGlobalScope('hotel')->sole()];
}

it('never changes another guest request, whatever id the tool is given', function () {
    [$hotel, $a, $aReservation, $b, , $bTask] = gsTwoGuests();

    gsRunTool(new CreateGuestServiceRequestTool($a, $hotel, $aReservation), ['kind' => 'maintenance_request',
        'title' => 'Lamp', 'description' => 'Desk lamp out', 'add_to_request_id' => $bTask->id,
    ]);
    gsRunTool(new CreateGuestServiceRequestTool($a, $hotel, $aReservation), [
        'title' => 'Towels', 'description' => 'Towels', 'add_to_request_id' => $bTask->id,
    ]);

    expect($bTask->fresh()->description)->toBe('Bilal sink')
        ->and(Task::withoutGlobalScope('hotel')->where('guest_id', $b->id)->count())->toBe(1)
        ->and(Task::withoutGlobalScope('hotel')->where('guest_id', $a->id)->count())->toBe(2);
});

it('never files a request against a room another guest occupies', function () {
    [$hotel, $a, $aReservation] = gsTwoGuests();

    gsRunTool(new CreateGuestServiceRequestTool($a, $hotel, $aReservation), ['kind' => 'maintenance_request',
        'title' => 'Noise', 'description' => 'Loud music next door', 'room_number' => '301',
    ]);

    $task = Task::withoutGlobalScope('hotel')->where('guest_id', $a->id)->sole();
    expect($task->room_id)->toBe(Room::withoutGlobalScope('hotel')->where('room_number', '214')->value('id'));
});

it('refuses a guest booking past the activity capacity, like staff', function () {
    [$hotel, $a, $aReservation] = gsTwoGuests();
    $activity = abActivity($hotel, ['name' => 'Kayak', 'daily_capacity' => 1]);

    $result = gsRunTool(new CreateBookingTool($hotel, $a, $aReservation), [
        'activity_id' => $activity->id, 'scheduled_for' => now($hotel->timezone)->addDay()->toDateString().' 10:00', 'pax' => 3,
    ]);

    expect(Booking::withoutGlobalScope('hotel')->count())->toBe(0)
        ->and($result)->toContain('cannot fit')->toContain('No booking was made');
});

it('records every guest action as done by the AI', function () {
    [$hotel, $a, $aReservation] = gsTwoGuests();

    gsRunTool(new EscalateToHumanTool($a, $hotel, $aReservation), ['reason' => 'Manager please']);
    $tasks = Task::withoutGlobalScope('hotel')->pluck('id');

    expect(EventLog::whereIn('subject_id', $tasks)->where('event_type', 'task.created')->pluck('actor_kind')->unique()->values()->all())
        ->toBe([ActorKind::AI_AGENT]);
});

it('shows the guest only their own reservation', function () {
    [$hotel, $a, $aReservation, $b] = gsTwoGuests();

    $output = gsRunTool(new GetOwnReservationTool($aReservation));

    expect($output)->toContain($aReservation->reservation_id)
        ->not->toContain('301')
        ->not->toContain('Bilal');
});

it('never credits a booking to another guest recommendation', function () {
    [$hotel, $a, $aReservation, , $bReservation] = gsTwoGuests();
    $activity = abActivity($hotel, ['name' => 'Kayak', 'daily_capacity' => 10]);
    $theirs = Recommendation::create([
        'hotel_id' => $hotel->id, 'reservation_id' => $bReservation->id, 'activity_id' => $activity->id, 'recommended_at' => now(),
    ]);

    gsRunTool(new CreateBookingTool($hotel, $a, $aReservation), [
        'activity_id' => $activity->id,
        'scheduled_for' => now($hotel->timezone)->addDay()->toDateString().' 10:00',
        'pax' => 1,
        'recommendation_id' => $theirs->id,
    ]);

    expect(Booking::withoutGlobalScope('hotel')->sole()->recommendation_id)->toBeNull();
});

describe('a guest with only a past stay', function () {
    it('cannot file service, maintenance or room-change requests', function () {
        [, $hotel] = gsHotel();
        $guest = gsGuest($hotel);
        $past = gsPast($hotel, $guest);

        $results = [
            gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $past), ['title' => 'Towels', 'description' => 'Towels']),
            gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $past), ['kind' => 'maintenance_request', 'title' => 'AC', 'description' => 'Broken']),
            gsRunTool(new RequestRoomChangeTool($guest, $hotel, $past), ['reason' => 'Bigger room']),
        ];

        foreach ($results as $result) {
            expect($result)->toBe("This needs a current or upcoming reservation at {$hotel->name}. The guest has none, so nothing was filed. Offer to connect them with staff instead.");
        }
        expect(Task::withoutGlobalScope('hotel')->count())->toBe(0);
    });

    it('cannot book an activity', function () {
        [, $hotel] = gsHotel();
        $guest = gsGuest($hotel);
        $activity = abActivity($hotel, ['daily_capacity' => 10]);

        gsRunTool(new CreateBookingTool($hotel, $guest, gsPast($hotel, $guest)), [
            'activity_id' => $activity->id, 'scheduled_for' => now()->addDay()->toDateString().' 10:00', 'pax' => 1,
        ]);

        expect(Booking::withoutGlobalScope('hotel')->count())->toBe(0);
    });

    it('can still reach a person and read activities', function () {
        [, $hotel] = gsHotel();
        $guest = gsGuest($hotel);
        $past = gsPast($hotel, $guest);
        abActivity($hotel, ['name' => 'Kayak tour']);

        gsRunTool(new EscalateToHumanTool($guest, $hotel, $past), ['reason' => 'Lost property']);
        $activities = gsRunTool(new GetActivitiesTool($hotel));

        expect(Task::withoutGlobalScope('hotel')->sole()->reservation_id)->toBeNull()
            ->and($activities)->toContain('Kayak tour');
    });
});

it('files a request against the guest live reservation when recognition picked a cancelled one', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $cancelled = gsUpcoming($hotel, $guest);
    $cancelled->forceFill([
        'status' => 'cancelled',
        'arrival_date' => now()->subDay()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
    ])->saveQuietly();
    $live = gsUpcoming($hotel, $guest);

    $result = gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $cancelled), ['kind' => 'maintenance_request', 'title' => 'AC', 'description' => 'Broken']);
    gsRunTool(new RequestRoomChangeTool($guest, $hotel, $cancelled), ['reason' => 'Sea view please']);

    expect($result)->toContain('Maintenance request filed')
        ->and(Task::withoutGlobalScope('hotel')->pluck('reservation_id')->unique()->values()->all())->toBe([$live->id]);
});

it('treats a guest still checked in after the planned departure as current', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    $reservation->forceFill(['departure_date' => now()->subDay()->toDateString()])->saveQuietly();
    $reservation->refresh();

    $result = gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), [
        'kind' => 'maintenance_request', 'title' => 'AC', 'description' => 'Broken',
    ]);
    $output = json_decode(gsRunTool(new GetOwnReservationTool($reservation)), true);

    expect($result)->toContain('Maintenance request filed')
        ->and($output['is_active'])->toBeTrue()
        ->and(Reservation::activeFor($hotel, $guest)?->id)->toBe($reservation->id);
});
