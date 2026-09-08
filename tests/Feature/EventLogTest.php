<?php

use App\Enums\ActorKind;
use App\Enums\EvidenceLevel;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\AiInsights;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\User;
use App\Services\StayService;
use App\Services\TransactionService;
use App\Support\Audit\EventLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function elApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function elAdminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Event Log Hotel',
        'slug' => 'event-log-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function elGuest(Hotel $hotel): Guest
{
    return Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
}

function elReservation(Hotel $hotel, Guest $guest): Reservation
{
    return Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
        'status' => ReservationStatus::CONFIRMED->value,
        'reservation_value' => 100,
    ]);
}

function lastEvent(string $eventType): ?EventLog
{
    return EventLog::withoutGlobalScope('hotel')
        ->where('event_type', $eventType)
        ->orderByDesc('occurred_at')
        ->first();
}

it('logs who changed a reservation value and the old value', function () {
    [$admin, $hotel] = elAdminWithHotel();
    $reservation = elReservation($hotel, elGuest($hotel));

    $this->actingAs($admin);
    $reservation->update(['reservation_value' => 250]);

    $event = lastEvent('reservation.updated');

    expect($event)->not->toBeNull()
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->actor_kind)->toBe(ActorKind::USER)
        ->and((float) $event->changes['reservation_value']['from'])->toBe(100.0)
        ->and((float) $event->changes['reservation_value']['to'])->toBe(250.0);
});

it('only records allow-listed attributes in changes', function () {
    [, $hotel] = elAdminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'phone_number' => '+20 100 000 0000']);

    // Changing the phone number recomputes identity_hash in the model's own
    // saving hook — that derived field must not leak into the audit trail.
    $guest->update(['phone_number' => '+20 111 111 1111', 'first_name' => 'Sara']);

    $event = lastEvent('guest.updated');

    expect($event->changes)->toHaveKeys(['phone_number', 'first_name'])
        ->and($event->changes)->not->toHaveKey('identity_hash');
});

it('records actor_kind = ai_agent for an AI-created insight', function () {
    [, $hotel] = elAdminWithHotel();

    EventLogger::asAiAgent(fn () => AiInsights::create([
        'hotel_id' => $hotel->id,
        'title' => 'Occupancy dipping midweek',
        'description' => 'Consider a midweek package.',
        'category' => 'general',
        'insight_type' => 'general',
    ]));

    $event = lastEvent('ai_insights.created');

    expect($event->actor_kind)->toBe(ActorKind::AI_AGENT)
        ->and($event->actor_id)->toBeNull();
});

it('defaults a new AI insight to evidence level L3', function () {
    [, $hotel] = elAdminWithHotel();

    $insight = AiInsights::create([
        'hotel_id' => $hotel->id,
        'title' => 'x',
        'description' => 'y',
        'category' => 'general',
        'insight_type' => 'general',
    ]);

    expect($insight->fresh()->evidence_level)->toBe(EvidenceLevel::L3);
});

it('keeps the event after its subject is soft-deleted', function () {
    [, $hotel] = elAdminWithHotel();
    $reservation = elReservation($hotel, elGuest($hotel));
    $id = $reservation->id;

    $reservation->delete();

    expect(EventLog::withoutGlobalScope('hotel')->where('subject_id', $id)->where('event_type', 'reservation.created')->exists())->toBeTrue()
        ->and(EventLog::withoutGlobalScope('hotel')->where('subject_id', $id)->where('event_type', 'reservation.deleted')->exists())->toBeTrue();
});

it('blocks updates and deletes on an event log row', function () {
    [, $hotel] = elAdminWithHotel();
    elGuest($hotel);
    $event = lastEvent('guest.created');

    expect(fn () => $event->update(['reason' => 'tampered']))->toThrow(RuntimeException::class);
    expect(fn () => $event->delete())->toThrow(RuntimeException::class);
});

it('names stay lifecycle events by status', function () {
    [, $hotel] = elAdminWithHotel();
    $stay = Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => elGuest($hotel)->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-04',
    ]);

    $service = app(StayService::class);
    $service->checkIn($stay, now());
    expect(lastEvent('stay.checked_in'))->not->toBeNull();

    $service->checkOut($stay, now());
    expect(lastEvent('stay.checked_out'))->not->toBeNull();
});

it('emits transaction.reversed on the original transaction', function () {
    [, $hotel] = elAdminWithHotel();
    $txn = app(TransactionService::class)->record([
        'hotel_id' => $hotel->id,
        'item_name' => 'Spa',
        'unit_price' => 80,
        'line_total' => 80,
        'currency' => 'USD',
        'transacted_at' => '2026-09-04 16:00:00',
        'business_date' => '2026-09-04',
        'source_system' => 'import',
        'external_reference' => 'REF-'.uniqid(),
    ]);

    app(TransactionService::class)->reverse($txn, 'guest disputed the charge');

    $event = lastEvent('transaction.reversed');

    expect($event)->not->toBeNull()
        ->and($event->subject_id)->toBe($txn->id)
        ->and($event->reason)->toBe('guest disputed the charge');
});

it('writes one summary event for an import, not one per row', function () {
    [$admin, $hotel] = elAdminWithHotel();

    $header = 'item_name,line_total,transacted_at';
    $rows = [
        'Dive,10,2026-09-04 10:00',
        'Spa,20,2026-09-04 11:00',
        'Lunch,30,2026-09-04 12:00',
    ];
    $path = tempnam(sys_get_temp_dir(), 'txn').'.csv';
    file_put_contents($path, implode("\n", [$header, ...$rows]));
    $file = new UploadedFile($path, 'txn.csv', 'text/csv', null, true);

    $this->withHeaders(elApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/transaction/import', ['file' => $file])
        ->assertOk();

    expect(EventLog::withoutGlobalScope('hotel')->where('event_type', 'transaction.created')->count())->toBe(0)
        ->and(EventLog::withoutGlobalScope('hotel')->where('event_type', 'hotel.transactions_imported')->count())->toBe(1);
});

it('returns a record history scoped to the caller hotel', function () {
    [$admin, $hotel] = elAdminWithHotel();
    [$otherAdmin] = elAdminWithHotel();

    $reservation = elReservation($hotel, elGuest($hotel));
    $this->actingAs($admin);
    $reservation->update(['reservation_value' => 500]);

    $this->withHeaders(elApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/history/reservation/{$reservation->id}")
        ->assertOk()
        ->assertJsonPath('body.meta.total', 2); // created + updated

    $this->withHeaders(elApiHeaders())->actingAs($otherAdmin, 'sanctum')
        ->getJson("/api/history/reservation/{$reservation->id}")
        ->assertNotFound();
});

it('rejects an unknown history type', function () {
    [$admin] = elAdminWithHotel();

    $this->withHeaders(elApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/history/widgets/some-id')
        ->assertNotFound();
});
