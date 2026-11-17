<?php

use App\Enums\GuestSignal;
use App\Models\Stay;
use App\Models\Task;
use App\Services\GuestRequestService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| Two messages asking for a person, or for another room, at the same moment
| must give one request (FR-017, FR-028, SC-005). A second connection commits a competing
| escalation just before ours is inserted, so the insert meets the unique
| index; that needs committed rows, hence DatabaseTruncation.
*/

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['database.connections.pgsql_b' => config('database.connections.'.config('database.default'))]);
    Notification::fake();
});

afterEach(function () {
    DB::purge('pgsql_b');
    $this->truncateTablesForAllConnections();
});

it('returns the escalation that won the race instead of failing or duplicating it', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $raced = false;

    Event::listen('eloquent.creating: '.Task::class, function (Task $task) use ($hotel, $guest, &$raced) {
        if ($raced || $task->guest_signal !== GuestSignal::ESCALATION) {
            return;
        }

        $raced = true;
        DB::connection('pgsql_b')->table('tasks')->insert([
            'id' => (string) str()->uuid(),
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'title' => 'Guest needs human assistance',
            'description' => 'From the other message',
            'created_by' => 'ai',
            'status' => 'pending',
            'priority' => 'high',
            'guest_signal' => 'escalation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    ['task' => $task, 'created' => $created] = app(GuestRequestService::class)->escalate($guest, $hotel, null, 'From this message');

    expect($created)->toBeFalse()
        ->and(Task::withoutGlobalScope('hotel')->count())->toBe(1)
        ->and($task->fresh()->description)->toContain('From the other message')->toContain('From this message');
});

it('keeps one room-change request when two messages race for the same stay', function () {
    [, $hotel] = gsHotel();
    $guest = gsGuest($hotel);
    $reservation = gsInHouse($hotel, $guest);
    $stay = Stay::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->sole();
    $raced = false;

    Event::listen('eloquent.creating: '.Task::class, function (Task $task) use ($hotel, $guest, $reservation, $stay, &$raced) {
        if ($raced || $task->guest_signal !== GuestSignal::ROOM_CHANGE_REQUEST) {
            return;
        }

        $raced = true;
        DB::connection('pgsql_b')->table('tasks')->insert([
            'id' => (string) str()->uuid(),
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => $reservation->id,
            'stay_id' => $stay->id,
            'title' => 'Room change request',
            'description' => 'From the other message',
            'created_by' => 'guest',
            'status' => 'pending',
            'priority' => 'normal',
            'guest_signal' => 'room_change_request',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    ['created' => $created] = app(GuestRequestService::class)->requestRoomChange($guest, $hotel, $reservation, $stay, 'Noisy', null);

    expect($created)->toBeFalse()
        ->and(Task::withoutGlobalScope('hotel')->where('guest_signal', 'room_change_request')->count())->toBe(1);
});
