<?php

use App\Enums\Permission;
use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Models\EventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks and undoes a whole no-show through the shared rules, audited as the AI', function () {
    $s = aatSeed();
    $tool = aatTool($s['admin'], 'NoShowTool');

    $result = aatCall($tool, ['action' => 'mark', 'reservation_id' => $s['code'], 'reason' => 'Flight cancelled']);

    expect($result)->toContain('marked as a no-show')
        ->and($s['reservation']->fresh()->status)->toBe(ReservationStatus::NO_SHOW);

    $event = EventLog::where('subject_id', $s['reservation']->id)->where('event_type', 'reservation.no_show_marked')->sole();
    expect($event->reason)->toBe('Flight cancelled')
        ->and($event->actor_kind->value ?? $event->actor_kind)->toBe('ai_agent');

    aatCall($tool, ['action' => 'undo', 'reservation_id' => $s['code']]);
    expect($s['reservation']->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('marks one room by its number and shows it in the reservation read tool', function () {
    $s = aatSeed();
    $reservation = aatReservation($s['hotel'], $s['type'], [$s['rooms']['101']->id, $s['rooms']['102']->id]);

    aatCall(aatTool($s['admin'], 'NoShowTool'), ['action' => 'mark', 'reservation_id' => $reservation->reservation_id, 'room_number' => '102']);

    [$first, $second] = fdStays($reservation);
    $read = aatCall(aatTool($s['admin'], 'GetReservationTool'), ['code' => $reservation->reservation_id]);
    $byRoom = collect($read['rooms'])->keyBy('room_number');

    expect($first->status)->toBe(StayStatus::EXPECTED)
        ->and($second->status)->toBe(StayStatus::NO_SHOW)
        ->and($byRoom['102']['stay_status'])->toBe('no_show')
        ->and($byRoom['101']['stay_status'])->toBe('expected');
});

it('returns the service reasons instead of acting', function () {
    $s = aatSeed();
    $future = aatReservation($s['hotel'], $s['type'], [null], [
        'arrival_date' => now()->addDays(3)->toDateString(),
        'departure_date' => now()->addDays(5)->toDateString(),
    ]);

    $result = aatCall(aatTool($s['admin'], 'NoShowTool'), ['action' => 'mark', 'reservation_id' => $future->reservation_id]);

    expect($result)->toStartWith('Not done:')
        ->and($future->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('refuses without the no-show permission, and for unknown codes and rooms', function () {
    $s = aatSeed();
    $employee = aatEmployee($s['hotel'], [Permission::RESERVATIONS_VIEW, Permission::RESERVATIONS_UPDATE]);

    $refused = aatCall(aatTool($employee, 'NoShowTool'), ['action' => 'undo', 'reservation_id' => $s['code']]);
    expect(strtolower(is_string($refused) ? $refused : json_encode($refused)))->toContain('permission');

    $tool = aatTool($s['admin'], 'NoShowTool');
    expect(aatCall($tool, ['action' => 'mark', 'reservation_id' => 'NOPE-1']))->toBe('This hotel has no reservation with that code.')
        ->and(aatCall($tool, ['action' => 'mark', 'reservation_id' => $s['code'], 'room_number' => '999']))->toContain('is not on reservation')
        ->and($s['reservation']->fresh()->status)->toBe(ReservationStatus::CONFIRMED);
});

it('marks an unassigned room by its type, skipping cancelled lines', function () {
    $s = aatSeed();
    $reservation = aatReservation($s['hotel'], $s['type'], [$s['rooms']['101']->id, null]);

    $result = aatCall(aatTool($s['admin'], 'NoShowTool'), ['action' => 'mark', 'reservation_id' => $reservation->reservation_id, 'room_type' => 'deluxe']);

    [$assigned, $unassigned] = fdStays($reservation);

    expect($result)->toContain('unassigned Deluxe')
        ->and($unassigned->status)->toBe(StayStatus::NO_SHOW)
        ->and($assigned->status)->toBe(StayStatus::EXPECTED);
});
