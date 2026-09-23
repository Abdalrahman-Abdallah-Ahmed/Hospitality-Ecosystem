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
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\BookingService;
use App\Services\TransactionService;
use Carbon\CarbonInterface;
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

/**
 * A pairing code as connect() issues it: named for pairing and expiring. The
 * device and webhook tests both redeem one.
 */
function pairingCodeFor(User $user, ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken(
        WhatsAppDevice::PAIRING_TOKEN_NAME,
        expiresAt: $expiresAt ?? now()->addMinutes(15),
    )->plainTextToken;
}

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

/**
 * Rooms require a room type. Tests that do not care which one file the room
 * under the hotel's default type, created on first use.
 */
function roomTypeIdFor(Hotel $hotel): string
{
    return RoomType::resolveFor($hotel->id)->id;
}

/**
 * A reservation with the given lines, written straight to the models (the
 * fixture path; it skips ReservationCreator's validation and occupancy sync).
 * Each line is ['room_type_id' => …, 'room_id' => ?, 'status' => ?]; a line
 * with a room but no type is filed under that room's type.
 *
 * @param  array<int, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $attributes
 */
function createReservationWithRooms(Hotel $hotel, array $lines, array $attributes = []): Reservation
{
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $attributes['guest_id'] ?? Guest::create([
            'hotel_id' => $hotel->id,
            'external_id' => 'ext-'.Str::random(8),
            'channel' => 'booking_com',
        ])->id,
        'reservation_id' => 'RES-'.Str::random(8),
        'arrival_date' => now()->toDateString(),
        'departure_date' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'adults' => 1,
        'children' => 0,
        ...$attributes,
    ]);

    foreach ($lines as $line) {
        $roomTypeId = $line['room_type_id']
            ?? (isset($line['room_id']) ? Room::withoutGlobalScope('hotel')->findOrFail($line['room_id'])->room_type_id : roomTypeIdFor($hotel));

        ReservationRoom::create([
            'hotel_id' => $hotel->id,
            'reservation_id' => $reservation->id,
            'room_type_id' => $roomTypeId,
            'room_id' => $line['room_id'] ?? null,
            'status' => $line['status'] ?? 'reserved',
        ]);
    }

    return $reservation;
}

/*
| Availability fixtures (SPEC-020), shared by the service, controller, guard,
| concurrency and AI tool tests.
*/

/**
 * A hotel whose owner is its admin (`$hotel->owner`).
 */
function avHotel(string $timezone = 'UTC'): Hotel
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Availability Hotel',
        'slug' => 'availability-hotel-'.$admin->id,
        'currency' => 'USD',
        'timezone' => $timezone,
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function avType(Hotel $hotel, string $name, bool $active = true): RoomType
{
    return RoomType::create([
        'hotel_id' => $hotel->id,
        'name' => $name,
        'max_occupancy' => 3,
        'adult_capacity' => 2,
        'child_capacity' => 1,
        'base_price' => 100,
        'is_active' => $active,
    ]);
}

/**
 * @return list<Room>
 */
function avRooms(Hotel $hotel, RoomType $type, int $count, ?string $status = null): array
{
    $rooms = [];

    for ($i = 0; $i < $count; $i++) {
        $rooms[] = Room::create([
            'hotel_id' => $hotel->id,
            'room_type_id' => $type->id,
            'room_number' => $type->name.'-'.uniqid(),
            'status' => $status ?? 'available',
        ]);
    }

    return $rooms;
}

/**
 * A reservation holding `$units` lines of one type, written straight to the
 * models (no availability guard).
 */
function avBook(Hotel $hotel, RoomType $type, string $arrival, string $departure, string $status = 'confirmed', int $units = 1, string $lineStatus = 'reserved'): Reservation
{
    return createReservationWithRooms(
        $hotel,
        array_fill(0, $units, ['room_type_id' => $type->id, 'status' => $lineStatus]),
        ['arrival_date' => $arrival, 'departure_date' => $departure, 'status' => $status],
    );
}

/**
 * The hotel's default room type with at least `$stock` rooms in it, for tests
 * that book through ReservationCreator and do not care about availability:
 * the guard (SPEC-020) rejects a booking of a type with no free room. Stock
 * rooms are numbered "STOCK-{n}" so they never collide with rooms a test names.
 */
function bookableTypeIdFor(Hotel $hotel, int $stock = 5): string
{
    $typeId = roomTypeIdFor($hotel);
    $have = Room::withoutGlobalScope('hotel')
        ->where('hotel_id', $hotel->id)
        ->where('room_type_id', $typeId)
        ->where('room_number', 'like', 'STOCK-%')
        ->count();

    for ($i = $have + 1; $i <= $stock; $i++) {
        Room::create(['hotel_id' => $hotel->id, 'room_type_id' => $typeId, 'room_number' => "STOCK-{$i}"]);
    }

    return $typeId;
}
