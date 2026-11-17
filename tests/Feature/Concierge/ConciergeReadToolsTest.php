<?php

use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\GetOwnBookingsTool;
use App\Ai\Tools\GetOwnRequestsTool;
use App\Ai\Tools\GetOwnReservationTool;
use App\Enums\StayStatus;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Services\BookingCancellationService;
use App\Services\StayLifecycleService;
use App\Support\Audit\EventLogger;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

it('shows the guest reservation with each room and its stay status', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $type = avType($hotel, 'Deluxe');
    $room = Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $type->id, 'room_number' => '214', 'status' => 'available', 'housekeeping_status' => 'clean']);
    avRooms($hotel, $type, 1);
    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id, 'guest_id' => $guest->id, 'reservation_id' => 'RES-'.Str::upper(Str::random(8)),
        'arrival_date' => now()->toDateString(), 'departure_date' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed', 'adults' => 2, 'children' => 0,
    ], [['room_type_id' => $type->id, 'room_id' => $room->id], ['room_type_id' => $type->id, 'room_id' => null]]);
    $stay = Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->where('room_id', $room->id)->sole();
    app(StayLifecycleService::class)->checkIn($stay);

    $output = json_decode(gsRunTool(new GetOwnReservationTool($reservation->fresh())), true);

    $rooms = collect($output['rooms'])->keyBy(fn ($line) => $line['room_number'] ?? 'unassigned');
    expect($output['is_active'])->toBeTrue()
        ->and($rooms['214']['stay_status'])->toBe(StayStatus::IN_HOUSE->value)
        ->and($rooms['unassigned']['stay_status'])->toBe(StayStatus::EXPECTED->value);
});

it('marks a past reservation as not current', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);

    $output = json_decode(gsRunTool(new GetOwnReservationTool(gsPast($hotel, $guest))), true);

    expect($output['is_active'])->toBeFalse();
});

it('lists the guest bookings, upcoming soonest first, with price and currency', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $cruise = abActivity($hotel, ['name' => 'Sunset cruise', 'price' => 90, 'daily_capacity' => 10]);
    $later = abBook($hotel, $cruise, now()->addDays(3)->toDateString(), 2, ['guest_id' => $guest->id, 'expected_value' => 180]);
    $sooner = abBook($hotel, $cruise, now()->addDay()->toDateString(), 1, ['guest_id' => $guest->id, 'expected_value' => 90]);
    abBook($hotel, $cruise, now()->addDay()->toDateString(), 1); // another guest's
    EventLogger::asAiAgent(fn () => app(BookingCancellationService::class)->request($later, $guest, 'Changed plans'));

    $output = json_decode(gsRunTool(new GetOwnBookingsTool($hotel, $guest)), true);

    expect($output['upcoming'])->toHaveCount(2)
        ->and($output['upcoming'][0]['reference'])->toBe($sooner->reference)
        ->and($output['upcoming'][1])->toMatchArray([
            'reference' => $later->reference,
            'activity' => 'Sunset cruise',
            'date' => $later->scheduled_date->toDateString(),
            'party_size' => 2,
            'currency' => 'USD',
            'cancellation_requested' => true,
        ])
        ->and((float) $output['upcoming'][1]['price'])->toBe(180.0)
        ->and($output['past'])->toBe([]);
});

it('says so when the guest has no bookings', function () {
    [, $hotel] = gsHotel();

    expect(gsRunTool(new GetOwnBookingsTool($hotel, gsGuest($hotel))))->toContain('no activity bookings');
});

it('lists only the guest open requests, without staff details', function () {
    [$admin, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $other = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214']);
    $otherReservation = gsInHouse($hotel, $other, ['301']);
    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), ['kind' => 'maintenance_request', 'title' => 'AC not cooling', 'description' => 'internal detail']);
    gsRunTool(new CreateGuestServiceRequestTool($guest, $hotel, $reservation), ['kind' => 'maintenance_request', 'title' => 'Lamp broken', 'description' => 'x']);
    gsRunTool(new CreateGuestServiceRequestTool($other, $hotel, $otherReservation), ['kind' => 'maintenance_request', 'title' => 'Other guest leak', 'description' => 'y']);
    $done = Task::withoutGlobalScope('hotel')->where('title', 'Lamp broken')->sole();
    $done->update(['status' => 'completed']);
    Task::withoutGlobalScope('hotel')->where('title', 'AC not cooling')->update(['status' => 'in_progress']);

    $output = json_decode(gsRunTool(new GetOwnRequestsTool($hotel, $guest)), true);

    expect($output)->toHaveCount(1)
        ->and($output[0])->toMatchArray(['kind' => 'maintenance_request', 'title' => 'AC not cooling', 'status' => 'in progress', 'room_number' => '214'])
        ->and(array_keys($output[0]))->toEqualCanonicalizing(['id', 'kind', 'title', 'status', 'created_at', 'room_number']);
});

it('reads each answer in a fixed number of queries', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest, ['214', '215']);
    $cruise = abActivity($hotel, ['daily_capacity' => 20]);
    foreach (range(1, 4) as $day) {
        abBook($hotel, $cruise, now()->addDays($day)->toDateString(), 1, ['guest_id' => $guest->id]);
    }

    foreach ([new GetOwnReservationTool($reservation), new GetOwnBookingsTool($hotel, $guest), new GetOwnRequestsTool($hotel, $guest)] as $tool) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        gsRunTool($tool);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($count)->toBeLessThanOrEqual(6, $tool::class);
    }
});

it('shows the guest live reservation when recognition picked a cancelled one', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $cancelled = gsUpcoming($hotel, $guest);
    $cancelled->forceFill([
        'status' => 'cancelled',
        'arrival_date' => now()->subDay()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
    ])->saveQuietly();
    $live = gsUpcoming($hotel, $guest);

    $output = json_decode(gsRunTool(new GetOwnReservationTool($cancelled)), true);

    expect($output['id'])->toBe($live->id)
        ->and($output['is_active'])->toBeTrue();
});
