<?php

use App\Ai\Tools\CreateBookingTool;
use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('generates a unique human-readable reference for every booking', function () {
    [, $hotel] = bookingAdminWithHotel();

    $first = bookingFor($hotel);
    $second = bookingFor($hotel);

    expect($first->reference)->toMatch('/^[23456789ABCDEFGHJKMNPQRTUVWXYZ]{3}-[23456789ABCDEFGHJKMNPQRTUVWXYZ]{4}$/')
        ->and($first->reference)->not->toBe($second->reference)
        // The characters that get misheard across a noisy desk are absent.
        ->and($first->reference)->not->toContain('0')
        ->and($first->reference)->not->toContain('O')
        ->and($first->reference)->not->toContain('1')
        ->and($first->reference)->not->toContain('I');
});

it('treats an INCLUDED booking as realised with no transaction present', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel, ['charge_model' => ChargeModel::INCLUDED->value]);

    app(BookingService::class)->realise($booking);

    // The whole reason the booking entity exists: an all-inclusive guest pays
    // nothing and the recommendation worked perfectly. Zero revenue, ideal
    // outcome. A payment-based definition would call this a failure.
    expect($booking->fresh()->status)->toBe(BookingStatus::REALISED)
        ->and($booking->fresh()->realised_at)->not->toBeNull()
        ->and($booking->transactions()->count())->toBe(0)
        ->and($booking->charge_model->settles())->toBeFalse();
});

it('never derives booking status from the existence of a transaction', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel);

    // A payment landing does not advance the booking...
    $transaction = bookingSettlement($hotel);
    app(BookingService::class)->linkSettlement($booking, $transaction);

    expect($booking->fresh()->status)->toBe(BookingStatus::PENDING);

    // ...and realising the booking does not invent a payment.
    app(BookingService::class)->realise($booking);

    expect(Transaction::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('records a no-show distinctly from a cancellation', function () {
    [, $hotel] = bookingAdminWithHotel();
    $noShow = bookingFor($hotel);
    $cancelled = bookingFor($hotel);

    app(BookingService::class)->markNoShow($noShow);
    app(BookingService::class)->cancel($cancelled, 'guest changed plans');

    // One broke a commitment, the other withdrew it in time. Different
    // operational responses, so they must not collapse into one state.
    expect($noShow->fresh()->status)->toBe(BookingStatus::NO_SHOW)
        ->and($noShow->fresh()->cancellation_reason)->toBeNull()
        ->and($cancelled->fresh()->status)->toBe(BookingStatus::CANCELLED)
        ->and($cancelled->fresh()->cancellation_reason)->toBe('guest changed plans');
});

it('cancels rather than deletes', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel);

    app(BookingService::class)->cancel($booking, 'weather');

    expect(Booking::withoutGlobalScope('hotel')->find($booking->id))->not->toBeNull()
        ->and($booking->fresh()->cancelled_at)->not->toBeNull();
});

it('refuses to reopen a cancelled booking', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel);

    app(BookingService::class)->cancel($booking, 'weather');

    expect(fn () => app(BookingService::class)->realise($booking))->toThrow(RuntimeException::class);
});

it('refuses to settle an included booking', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel, ['charge_model' => ChargeModel::INCLUDED->value]);

    expect(fn () => app(BookingService::class)->linkSettlement($booking, bookingSettlement($hotel)))
        ->toThrow(RuntimeException::class);
});

it('distinguishes a recommendation-origin booking from a guest-request one', function () {
    [, $hotel] = bookingAdminWithHotel();

    $fromRecommendation = bookingFor($hotel, ['origin' => BookingOrigin::RECOMMENDATION->value]);
    $organic = bookingFor($hotel, ['origin' => BookingOrigin::GUEST_REQUEST->value]);

    // Without origin you cannot tell whether the agent creates demand or
    // merely records demand that already existed.
    expect($fromRecommendation->origin)->toBe(BookingOrigin::RECOMMENDATION)
        ->and($organic->origin)->toBe(BookingOrigin::GUEST_REQUEST);
});

it('links a transaction to a booking by reference and leaves walk-ups unlinked', function () {
    [$admin, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel);

    $csv = "item_name,line_total,transacted_at,booking_reference\n"
        ."Sunset dive,60,2026-09-03 18:00,{$booking->reference}\n"
        .'Beer,5,2026-09-03 19:00,';
    $path = tempnam(sys_get_temp_dir(), 'bk').'.csv';
    file_put_contents($path, $csv);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->postJson('/api/transaction/import', ['file' => new UploadedFile($path, 'txn.csv', 'text/csv', null, true)])
        ->assertOk()
        ->assertJsonPath('body.imported', 2)
        ->assertJsonPath('body.booking_links', 1)
        ->assertJsonPath('body.unknown_booking_references', 0);

    expect(Transaction::where('booking_id', $booking->id)->count())->toBe(1)
        // The walk-up sale is unlinked, which is normal, not an error.
        ->and(Transaction::where('hotel_id', $hotel->id)->whereNull('booking_id')->count())->toBe(1);
});

it('keeps an unresolvable booking reference on the row and counts it', function () {
    [$admin, $hotel] = bookingAdminWithHotel();

    $csv = "item_name,line_total,transacted_at,booking_reference\nSunset dive,60,2026-09-03 18:00,ZZZ-9999";
    $path = tempnam(sys_get_temp_dir(), 'bk').'.csv';
    file_put_contents($path, $csv);

    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->postJson('/api/transaction/import', ['file' => new UploadedFile($path, 'txn.csv', 'text/csv', null, true)])
        ->assertOk()
        ->assertJsonPath('body.booking_links', 0)
        ->assertJsonPath('body.unknown_booking_references', 1);

    expect(Transaction::where('hotel_id', $hotel->id)->value('booking_reference'))->toBe('ZZZ-9999');
});

it('writes an event_log entry for every status change', function () {
    [, $hotel] = bookingAdminWithHotel();
    $booking = bookingFor($hotel);
    $service = app(BookingService::class);

    $service->confirm($booking);
    $service->realise($booking);

    $types = EventLog::withoutGlobalScope('hotel')
        ->where('subject_id', $booking->id)
        ->pluck('event_type');

    expect($types)->toContain('booking.created')
        ->and($types)->toContain('booking.confirmed')
        ->and($types)->toContain('booking.realised');
});

it('creates a booking from the concierge tool and carries the recommendation id', function () {
    [, $hotel] = bookingAdminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Sunset dive', 'price' => 60, 'currency' => 'USD']);
    $recommendation = Recommendation::create(['hotel_id' => $hotel->id, 'activity_id' => $activity->id]);

    $tool = new CreateBookingTool($hotel, $guest);
    $result = (string) $tool->handle(new Request([
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
        'pax' => 2,
        'charge_model' => ChargeModel::INCLUDED->value,
    ]));

    $booking = Booking::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->first();

    expect($booking)->not->toBeNull()
        ->and($booking->recommendation_id)->toBe($recommendation->id)
        ->and($booking->origin)->toBe(BookingOrigin::RECOMMENDATION)
        ->and($booking->charge_model)->toBe(ChargeModel::INCLUDED)
        ->and($booking->pax)->toBe(2)
        ->and($booking->channel)->toBe('whatsapp')
        // The guest has to be told the code, or nobody can quote it later.
        ->and($result)->toContain($booking->reference);
});

it('treats a booking the guest asked for unprompted as guest_request', function () {
    [, $hotel] = bookingAdminWithHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Spa', 'price' => 40, 'currency' => 'USD']);

    (new CreateBookingTool($hotel, $guest))->handle(new Request([
        'activity_id' => $activity->id,
    ]));

    expect(Booking::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->first()->origin)
        ->toBe(BookingOrigin::GUEST_REQUEST);
});

// --- local fixtures ------------------------------------------------------

function bookingAdminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Booking Hotel',
        'slug' => 'booking-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function bookingFor(Hotel $hotel, array $overrides = []): Booking
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

    return app(BookingService::class)->create(array_merge([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'item_name' => 'Sunset dive',
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'origin' => BookingOrigin::GUEST_REQUEST->value,
        'expected_value' => 60,
        'currency' => 'USD',
    ], $overrides));
}

function bookingSettlement(Hotel $hotel, array $overrides = []): Transaction
{
    return app(TransactionService::class)->record(array_merge([
        'hotel_id' => $hotel->id,
        'item_name' => 'Sunset dive',
        'line_total' => 60,
        'currency' => 'USD',
        'transacted_at' => '2026-09-03 18:00:00',
        'business_date' => '2026-09-03',
        'source_system' => 'import',
        'external_reference' => 'REF-'.uniqid(),
    ], $overrides));
}
