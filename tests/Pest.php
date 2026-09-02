<?php

use App\Enums\BookingOrigin;
use App\Enums\ChargeModel;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
| WP-5 (close the loop) fixtures. They live here rather than in one of the
| three test files that need them so no file depends on another having been
| loaded first.
*/

function wp5Headers(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function wp5AdminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Close The Loop Hotel',
        'slug' => 'close-the-loop-'.uniqid(),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

/**
 * A recommendation with everything the matcher and the report need: a guest,
 * a reservation, and an activity, all in one hotel.
 *
 * @return array{0: Recommendation, 1: Guest, 2: Activity}
 */
function wp5Recommendation(Hotel $hotel, array $overrides = []): array
{
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.uniqid(),
        'channel' => 'booking_com',
    ]);

    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-06',
        'status' => 'confirmed',
    ]);

    $activity = Activity::create([
        'hotel_id' => $hotel->id,
        'name' => 'Sunset dive '.uniqid(),
        'price' => 60,
        'currency' => 'USD',
    ]);

    $recommendation = Recommendation::create(array_merge([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'recommended_at' => now()->subHours(9),
    ], $overrides));

    return [$recommendation, $guest, $activity];
}

function wp5Booking(Hotel $hotel, array $overrides = []): Booking
{
    return app(BookingService::class)->create(array_merge([
        'hotel_id' => $hotel->id,
        'item_name' => 'Sunset dive',
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'origin' => BookingOrigin::GUEST_REQUEST->value,
        'expected_value' => 60,
        'currency' => 'USD',
    ], $overrides));
}

function wp5Transaction(Hotel $hotel, array $overrides = []): Transaction
{
    return app(TransactionService::class)->record(array_merge([
        'hotel_id' => $hotel->id,
        'item_name' => 'Sunset dive',
        'line_total' => 60,
        'currency' => 'USD',
        'transacted_at' => now(),
        'business_date' => now()->toDateString(),
        'source_system' => 'import',
        'external_reference' => 'REF-'.uniqid(),
    ], $overrides));
}

function wp5Outcome(Recommendation $recommendation): ?RecommendationOutcome
{
    return RecommendationOutcome::withoutGlobalScope('hotel')
        ->where('recommendation_id', $recommendation->id)
        ->first();
}
