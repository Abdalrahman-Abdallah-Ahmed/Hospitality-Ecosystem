<?php

use App\Ai\Tools\CancelReservationTool;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Stay;
use App\Support\Reservations\ReservationLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/** A reservation in `$status`, reached the way the desk would reach it. */
function lcReservation($test, string $status): Reservation
{
    [$hotel, $type, [$room]] = fdHotel();
    $reservation = fdBook($hotel, $type, [$room->id], in_array($status, ['pending', 'cancelled'], true) ? ['status' => $status] : []);
    [$stay] = fdStays($reservation);

    match ($status) {
        'checked_in' => fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk(),
        'checked_out' => [
            fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk(),
            fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk(),
        ],
        'no_show' => fdPost($test, $hotel->owner, "/api/reservation/{$reservation->id}/no-show")->assertOk(),
        default => null,
    };

    return $reservation->fresh();
}

function lcPut($test, Reservation $reservation, array $payload)
{
    $owner = $reservation->hotel()->first()->owner;

    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($owner, 'sanctum')
        ->putJson("/api/reservation/{$reservation->id}", $payload);
}

it('allows an edit-path status change only when the lifecycle table does', function (string $from, string $to) {
    $reservation = lcReservation($this, $from);
    $allowed = ReservationLifecycle::allows(ReservationStatus::from($from), ReservationStatus::from($to), ReservationLifecycle::EDIT);

    $response = lcPut($this, $reservation, ['status' => $to]);

    if ($allowed) {
        $response->assertOk();
        expect($reservation->fresh()->status->value)->toBe($to);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('status');
        expect($reservation->fresh()->status->value)->toBe($from);
    }
})->with(function () {
    foreach (['pending', 'confirmed', 'cancelled', 'checked_in', 'checked_out', 'no_show'] as $from) {
        foreach (['pending', 'confirmed', 'cancelled', 'no_show'] as $to) {
            if ($from !== $to) {
                yield "{$from} → {$to}" => [$from, $to];
            }
        }
    }
});

it('names both statuses when it refuses a change', function () {
    $reservation = lcReservation($this, 'checked_out');

    lcPut($this, $reservation, ['status' => 'pending'])
        ->assertJsonValidationErrors(['status' => "A checked_out reservation can't be changed to pending."]);
});

it('points a no-show edit to its own endpoints', function () {
    $confirmed = lcReservation($this, 'confirmed');
    lcPut($this, $confirmed, ['status' => 'no_show'])
        ->assertJsonValidationErrors(['status' => "Use POST /reservation/{$confirmed->id}/no-show."]);

    $noShow = lcReservation($this, 'no_show');
    lcPut($this, $noShow, ['status' => 'confirmed'])
        ->assertJsonValidationErrors(['status' => "Use POST /reservation/{$noShow->id}/undo-no-show."]);
});

it('refuses to create a reservation as a no-show', function () {
    [$admin, $hotel] = adminWithHotel();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        'hotel_id' => $hotel->id,
        'guest_id' => gsGuest($hotel)->id,
        'reservation_id' => 'LC-NEW-1',
        'arrival_date' => now()->addDays(3)->toDateString(),
        'departure_date' => now()->addDays(5)->toDateString(),
        'status' => 'no_show',
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('lets the AI cancel only what the table allows', function () {
    $noShow = lcReservation($this, 'no_show');
    $result = (string) (new CancelReservationTool($noShow->hotel()->first()))->handle(new Request(['code' => $noShow->reservation_id]));

    expect($noShow->fresh()->status)->toBe(ReservationStatus::NO_SHOW)
        ->and($result)->toContain('undo-no-show');
});

it('lets the import record a past no-show', function () {
    [$admin, $hotel] = adminWithHotel();
    imImport($this, $admin, imCsv(['guest_phone', 'arrival_date', 'departure_date', 'status'], [
        ['555-0901', now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString(), 'no_show'],
    ]));

    $reservation = Reservation::where('hotel_id', $hotel->id)->sole();

    expect($reservation->status)->toBe(ReservationStatus::NO_SHOW)
        ->and(Stay::where('reservation_id', $reservation->id)->pluck('status')->map->value->all())->toBe(['no_show']);
});

// --- Edit lock while a no-show (FR-010a) ------------------------------------

it('locks a no-show reservation except its guest list and special requests', function (string $field, mixed $value) {
    $reservation = lcReservation($this, 'no_show');

    lcPut($this, $reservation, [$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => 'Undo the no-show first.']);
})->with([
    'arrival_date' => ['arrival_date', now()->addDays(20)->toDateString()],
    'departure_date' => ['departure_date', now()->addDays(20)->toDateString()],
    'adults' => ['adults', 2],
    'source' => ['source', 'phone'],
    'reservation_value' => ['reservation_value', 999],
    'currency' => ['currency', 'EUR'],
    'reservation_id' => ['reservation_id', 'LC-RENAMED'],
]);

it('rejects room changes on a no-show reservation', function () {
    $reservation = lcReservation($this, 'no_show');
    $typeId = $reservation->reservationRooms()->value('room_type_id');

    lcPut($this, $reservation, ['rooms' => [['room_type_id' => $typeId, 'quantity' => 2]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rooms' => 'Undo the no-show first.']);
});

it('still saves special requests, the guest list and unchanged values on a no-show', function () {
    $reservation = lcReservation($this, 'no_show');
    $hotel = $reservation->hotel()->first();
    $other = gsGuest($hotel, ['phone_number' => '+201000000901']);

    lcPut($this, $reservation, [
        'special_requests' => 'Rebook next month',
        'adults' => $reservation->adults,
        'arrival_date' => $reservation->arrival_date->toDateString(),
        'guests' => [['guest_id' => $other->id, 'is_primary' => true]],
    ])->assertOk();

    expect($reservation->fresh()->special_requests)->toBe('Rebook next month')
        ->and($reservation->fresh()->guest_id)->toBe($other->id)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::NO_SHOW);
});
