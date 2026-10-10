<?php

use App\Ai\Agents\DocumentVisionAgent;
use App\Ai\Agents\TurnSignalAgent;
use App\Ai\Tools\Admin\AdminToolset;
use App\Ai\Tools\Admin\GuardedTool;
use App\Ai\Tools\KnowledgeSearchTool;
use App\Ai\Tools\PitchActivityTool;
use App\Ai\Tools\UpdateRecommendationTool;
use App\Enums\BookingOrigin;
use App\Enums\ChargeModel;
use App\Enums\KnowledgeAudience;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Jobs\EvaluateProactiveTriggersJob;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffRole;
use App\Models\Stay;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Models\WhatsAppInboundMessage;
use App\Services\BookingService;
use App\Services\Pitching\PitchCoordinator;
use App\Services\StayLifecycleService;
use App\Services\TransactionService;
use App\Services\WhatsAppMessageService;
use App\Support\Audit\EventLogger;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Knowledge\Extraction\Segment;
use App\Support\PhoneNumber;
use App\Support\Pitching\PitchTurn;
use App\Support\Proactive\ProactiveSettings;
use App\Support\Reservations\ReservationCreator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
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
        // Approved: these fixtures stand for recommendations a guest may be offered.
        'status' => 'approved',
        'recommended_at' => now()->subHours(9),
    ], $overrides));

    return [$recommendation, $guest, $activity];
}

function wp5Booking(Hotel $hotel, array $overrides = []): Booking
{
    return app(BookingService::class)->create(array_merge([
        'hotel_id' => $hotel->id,
        'scheduled_date' => now()->toDateString(),
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

/*
 * Shared by the reservation tests (controller, import, room occupancy). Kept
 * here, not in one of those files, so each file runs on its own and in
 * parallel.
 */
function apiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function adminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function reservationFor(Hotel $hotel, array $overrides = []): Reservation
{
    $guest = Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.$hotel->id,
        'channel' => 'booking_com',
    ]);

    return Reservation::create(array_merge([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => '2026-09-01',
        'departure_date' => '2026-09-04',
    ], $overrides));
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

/*
| Front-desk fixtures (SPEC-023/024/025), shared by the check-in, check-out,
| list, deprecation, AI tool and task tests.
*/

/**
 * A hotel (its owner is the admin) with a Deluxe type and `$rooms` rooms.
 *
 * @return array{0: Hotel, 1: RoomType, 2: list<Room>}
 */
function fdHotel(int $rooms = 3, string $timezone = 'UTC'): array
{
    $hotel = avHotel($timezone);
    $type = avType($hotel, 'Deluxe');

    return [$hotel, $type, avRooms($hotel, $type, $rooms)];
}

/**
 * A reservation booked through ReservationCreator, arriving today (hotel
 * time) for two nights, one line per entry of `$roomIds` (null: unassigned).
 *
 * @param  list<?string>  $roomIds
 * @param  array<string, mixed>  $attributes
 */
function fdBook(Hotel $hotel, RoomType $type, array $roomIds, array $attributes = []): Reservation
{
    $today = now($hotel->timezone)->toDateString();

    return ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.Str::random(8), 'channel' => 'booking_com', 'first_name' => 'Guest', 'last_name' => Str::random(5)])->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => $today,
        'departure_date' => now($hotel->timezone)->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'adults' => 1,
        'children' => 0,
        'reservation_value' => 200,
        ...$attributes,
    ], array_map(fn (?string $roomId) => ['room_type_id' => $type->id, 'room_id' => $roomId], $roomIds));
}

/**
 * The reservation's stays in line order.
 *
 * @return list<Stay>
 */
function fdStays(Reservation $reservation): array
{
    return $reservation->reservationRooms()->get()
        ->map(fn (ReservationRoom $line) => Stay::withoutGlobalScope('hotel')->where('reservation_room_id', $line->id)->first())
        ->filter()
        ->values()
        ->all();
}

/**
 * @param  list<Permission>  $permissions
 */
function fdEmployee(Hotel $hotel, array $permissions): User
{
    $role = StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);
}

function fdPost($test, User $user, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->postJson($uri, $payload);
}

function fdGet($test, User $user, string $uri): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->getJson($uri);
}

/*
|--------------------------------------------------------------------------
| Housekeeping and maintenance fixtures (SPEC-030/033/035)
|--------------------------------------------------------------------------
*/

function hkPut($test, User $user, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->putJson($uri, $payload);
}

function hkPatch($test, User $user, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->patchJson($uri, $payload);
}

/**
 * Checks a guest out of a room booked yesterday, which leaves the room dirty
 * with one check-out cleaning task.
 *
 * @return array{0: Hotel, 1: Room, 2: Task}
 */
function hkVacatedRoom($test, string $timezone = 'UTC'): array
{
    [$hotel, $type, [$room]] = fdHotel(1, $timezone);
    $test->travelTo(now()->subDay()->startOfDay()->addHours(14));
    $reservation = fdBook($hotel, $type, [$room->id], [
        'arrival_date' => now()->toDateString(),
        'departure_date' => now()->addDay()->toDateString(),
    ]);
    [$stay] = fdStays($reservation);
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-in")->assertOk();
    $test->travelBack();
    $test->travelTo(now()->startOfDay()->addHours(11));
    fdPost($test, $hotel->owner, "/api/stays/{$stay->id}/check-out")->assertOk();

    $task = Task::withoutGlobalScope('hotel')->where('room_id', $room->id)->sole();

    return [$hotel->fresh(), $room->fresh(), $task];
}

function hkSetTaskStatus($test, User $user, Task $task, string $status): TestResponse
{
    return hkPut($test, $user, "/api/task/{$task->id}", ['status' => $status]);
}

/*
|--------------------------------------------------------------------------
| Activity availability and booking fixtures (SPEC-041/043)
|--------------------------------------------------------------------------
*/

/**
 * An active activity of the hotel, re-read so database defaults are loaded.
 *
 * @param  array<string, mixed>  $attributes
 */
function abActivity(Hotel $hotel, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope('hotel')->findOrFail(Activity::create([
        'hotel_id' => $hotel->id,
        'name' => 'Sunset cruise',
        'price' => 50,
        'currency' => 'USD',
        'is_active' => true,
        ...$attributes,
    ])->id);
}

function abGuest(Hotel $hotel): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'external_id' => 'ext-'.Str::random(8),
        'channel' => 'booking_com',
        'first_name' => 'Guest',
        'last_name' => Str::random(5),
    ]);
}

/**
 * A booking of `$pax` people on `$date` (Y-m-d, or Y-m-d H:i for a time),
 * taken through BookingService so it is checked like any other.
 *
 * @param  array<string, mixed>  $attributes
 */
function abBook(Hotel $hotel, Activity $activity, string $date, int $pax = 1, array $attributes = []): Booking
{
    $schedule = strlen($date) > 10 ? ['scheduled_for' => $date] : ['scheduled_date' => $date];

    return app(BookingService::class)->create([
        'hotel_id' => $hotel->id,
        'guest_id' => abGuest($hotel)->id,
        'activity_id' => $activity->id,
        'item_name' => $activity->name,
        'pax' => $pax,
        'charge_model' => ChargeModel::PAY_ON_SITE->value,
        'origin' => BookingOrigin::STAFF->value,
        ...$schedule,
        ...$attributes,
    ]);
}

function abPatch($test, User $user, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->patchJson($uri, $payload);
}

/*
|--------------------------------------------------------------------------
| Guest services (SPEC 007)
|--------------------------------------------------------------------------
*/

/**
 * A hotel with its default Housekeeping and Maintenance teams and categories
 * (created on hotel creation), and its admin.
 *
 * @return array{0: User, 1: Hotel}
 */
function gsHotel(string $timezone = 'UTC'): array
{
    $hotel = avHotel($timezone);

    return [$hotel->owner, $hotel->fresh()];
}

/**
 * A guest reachable on WhatsApp and by email unless overridden.
 *
 * @param  array<string, mixed>  $attributes
 */
function gsGuest(Hotel $hotel, array $attributes = []): Guest
{
    return Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => 'Lina',
        'last_name' => 'Haddad',
        'phone_number' => '+20 100 '.random_int(1000000, 9999999),
        'email' => 'guest-'.Str::lower(Str::random(6)).'@example.com',
        'external_id' => 'ext-'.Str::random(8),
        'channel' => 'booking_com',
        ...$attributes,
    ]);
}

/**
 * A reservation for `$guest` arriving today, checked in, with one in-house
 * stay per room number.
 *
 * @param  list<string>  $roomNumbers
 */
function gsInHouse(Hotel $hotel, Guest $guest, array $roomNumbers = ['214']): Reservation
{
    $type = avType($hotel, 'Deluxe '.Str::random(4));
    $rooms = array_map(fn (string $number) => Room::create([
        'hotel_id' => $hotel->id,
        'room_type_id' => $type->id,
        'room_number' => $number,
        'status' => 'available',
        'housekeeping_status' => 'clean',
    ]), $roomNumbers);

    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => now($hotel->timezone)->toDateString(),
        'departure_date' => now($hotel->timezone)->addDays(3)->toDateString(),
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        'reservation_value' => 600,
    ], array_map(fn (Room $room) => ['room_type_id' => $type->id, 'room_id' => $room->id], $rooms));

    foreach (fdStays($reservation) as $stay) {
        app(StayLifecycleService::class)->checkIn($stay);
    }

    return $reservation->fresh();
}

/**
 * A confirmed reservation for `$guest` arriving in 3 days, rooms unassigned.
 */
function gsUpcoming(Hotel $hotel, Guest $guest): Reservation
{
    $type = avType($hotel, 'Deluxe '.Str::random(4));
    avRooms($hotel, $type, 1);

    return ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => now($hotel->timezone)->addDays(3)->toDateString(),
        'departure_date' => now($hotel->timezone)->addDays(5)->toDateString(),
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        'reservation_value' => 400,
    ], [['room_type_id' => $type->id, 'room_id' => null]]);
}

/**
 * A checked-out reservation for `$guest` that departed 30 days ago.
 */
function gsPast(Hotel $hotel, Guest $guest): Reservation
{
    $type = avType($hotel, 'Deluxe '.Str::random(4));
    avRooms($hotel, $type, 1);

    return ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => now($hotel->timezone)->subDays(33)->toDateString(),
        'departure_date' => now($hotel->timezone)->subDays(30)->toDateString(),
        'status' => 'checked_out',
        'adults' => 2,
        'children' => 0,
        'reservation_value' => 300,
    ], [['room_type_id' => $type->id, 'room_id' => null]]);
}

/**
 * An inbound WhatsApp message from `$phoneDigits` at `$at`, which opens (or,
 * when old enough, no longer opens) the 24-hour window.
 */
function gsInbound(string $phoneDigits, CarbonInterface $at): WhatsAppInboundMessage
{
    $message = WhatsAppInboundMessage::create([
        'wamid' => 'wamid.'.Str::random(16),
        'phone_number' => $phoneDigits,
        'message_type' => 'text',
        'status' => 'replied',
    ]);
    $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

    return $message;
}

/**
 * Runs a Concierge tool as the AI agent, the way the WhatsApp job does.
 *
 * @param  array<string, mixed>  $args
 */
function gsRunTool(object $tool, array $args = []): string
{
    return EventLogger::asAiAgent(fn () => (string) $tool->handle(new Request($args)));
}

/**
 * Swaps the WhatsApp sender for one that records each message, and the
 * tenant scope active while it was sent. `$failures` sends throw first.
 */
function gsFakeWhatsApp(int $failures = 0): object
{
    $fake = new class($failures) extends WhatsAppMessageService
    {
        /** @var list<array{to: string, text: string, hotel_ids: ?array}> */
        public array $sent = [];

        public int $attempts = 0;

        public function __construct(public int $failures) {}

        public function send(string $to, string $text): void
        {
            $this->attempts++;

            if ($this->failures-- > 0) {
                throw new RuntimeException('Graph API unavailable');
            }

            $this->sent[] = ['to' => $to, 'text' => $text, 'hotel_ids' => TenantContext::hotelIds()];
        }
    };

    app()->instance(WhatsAppMessageService::class, $fake);

    return $fake;
}

/*
|--------------------------------------------------------------------------
| Knowledge documents and RAG (SPEC 008)
|--------------------------------------------------------------------------
*/

/**
 * A hotel and its admin.
 *
 * @return array{0: User, 1: Hotel}
 */
function knHotel(): array
{
    $hotel = avHotel();

    return [$hotel->owner, $hotel->fresh()];
}

/**
 * @param  list<Permission>  $permissions
 */
function knEmployee(Hotel $hotel, array $permissions): User
{
    return fdEmployee($hotel, $permissions);
}

function knSuperAdmin(): User
{
    return User::factory()->role(UserRole::SUPER_ADMIN)->create();
}

/**
 * The same unit vector for every input, so every passage is maximally similar
 * to every query and the tests isolate scoping, not relevance ranking.
 */
function knFakeEmbeddings(): void
{
    Embeddings::fake(function ($prompt) {
        $value = 1 / sqrt($prompt->dimensions);

        return array_fill(0, count($prompt->inputs), array_fill(0, $prompt->dimensions, $value));
    });
}

function knFixturePath(string $fixture): string
{
    return __DIR__.'/Fixtures/knowledge/'.$fixture;
}

function knUpload(string $fixture, ?string $as = null): UploadedFile
{
    $path = knFixturePath($fixture);

    return new UploadedFile($path, $as ?? $fixture, null, null, true);
}

/**
 * An indexed document with live passages, written straight to the models
 * (no pipeline run). `$hotel = null` makes it global.
 *
 * @param  list<array{location: ?string, text: string}>|null  $segments
 * @param  array<string, mixed>  $attributes
 */
function knDocument(?Hotel $hotel, array $attributes = [], ?array $segments = null): KnowledgeDocument
{
    $segments ??= [['location' => 'Page 1', 'text' => 'Checkout is at 11:00.']];

    $document = KnowledgeDocument::withoutGlobalScope('hotel')->create([
        'hotel_id' => $hotel?->id,
        'title' => 'House Rules',
        'original_filename' => 'house-rules.pdf',
        'disk' => config('knowledge.disk'),
        'path' => 'knowledge/'.($hotel?->id ?? 'global').'/'.Str::uuid().'/file.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1000,
        'content_hash' => hash('sha256', Str::random(32)),
        'segments' => $segments,
        'content' => collect($segments)->map(fn ($s) => trim(($s['location'] ?? '')."\n".$s['text']))->implode("\n\n"),
        'status' => 'indexed',
        'indexed_at' => now(),
        'page_count' => count($segments),
        ...$attributes,
    ]);

    $chunks = ChunkSynchronizer::syncSegments(
        source: $document,
        segments: array_map(fn (array $segment) => Segment::fromArray($segment), $segments),
        hotelId: $document->hotel_id,
        category: $document->category?->value,
    );

    $document->forceFill(['chunk_count' => $chunks, 'index_fingerprint' => $document->inputFingerprint()])->saveQuietly();

    return $document->fresh();
}

/**
 * Fakes the vision agent: one response, `pages` as DocumentVisionAgent returns them.
 *
 * @param  list<array{page: int, text: string, description: string}>  $pages
 */
function knFakeVision(array $pages): void
{
    DocumentVisionAgent::fake([['pages' => $pages]]);
}

function knRequest($test, User $user, string $method, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->json($method, $uri, $payload);
}

function knSearch(Hotel $hotel, string $query = 'checkout', KnowledgeAudience $audience = KnowledgeAudience::STAFF): string
{
    return (string) (new KnowledgeSearchTool($hotel, $audience))->handle(new Request(['query' => $query]));
}

/*
|--------------------------------------------------------------------------
| Admin AI tools (SPEC-055) — prefix `aat`
|--------------------------------------------------------------------------
*/

/**
 * A hotel whose owner is its admin, with one room type ("Deluxe").
 *
 * @return array{0: Hotel, 1: User, 2: RoomType}
 */
function aatHotel(string $timezone = 'UTC'): array
{
    $hotel = avHotel($timezone);

    return [$hotel->fresh(), $hotel->owner, avType($hotel, 'Deluxe')];
}

/**
 * An employee holding exactly these permissions through a staff role.
 *
 * @param  list<Permission>  $permissions
 */
function aatEmployee(Hotel $hotel, array $permissions = []): User
{
    return fdEmployee($hotel, $permissions);
}

/**
 * The Admin AI tool with this name, built through the real registry and
 * guard for `$user`, exactly as the advisor gets it.
 */
function aatTool(User $user, string $name, ?string $conversationId = null, string $locale = 'en'): GuardedTool
{
    $tool = collect(AdminToolset::for($user, $conversationId, $locale))->first(fn (GuardedTool $tool) => $tool->name() === $name);

    if (! $tool) {
        throw new RuntimeException("AdminToolset has no tool named {$name}.");
    }

    return $tool;
}

/**
 * Call a tool the way the AI package does; JSON results come back decoded.
 */
function aatCall(object $tool, array $args = [], ?string $toolCallId = null): array|string
{
    $result = (string) $tool->handle(new Request($args, $toolCallId));
    $decoded = json_decode($result, true);

    return is_array($decoded) ? $decoded : $result;
}

function aatRoom(Hotel $hotel, RoomType $type, string $number, array $attributes = []): Room
{
    return Room::create([
        'hotel_id' => $hotel->id,
        'room_type_id' => $type->id,
        'room_number' => $number,
        'status' => 'available',
        'housekeeping_status' => 'clean',
        ...$attributes,
    ]);
}

function aatGuest(Hotel $hotel, array $attributes = []): Guest
{
    return gsGuest($hotel, $attributes);
}

/**
 * A reservation through ReservationCreator: one line per entry of `$roomIds`
 * (null: unassigned), arriving today for two nights unless overridden.
 *
 * @param  list<?string>  $roomIds
 */
function aatReservation(Hotel $hotel, RoomType $type, array $roomIds, array $attributes = []): Reservation
{
    return fdBook($hotel, $type, $roomIds, $attributes);
}

/**
 * One hotel's worth of records for the Admin AI tool datasets: rooms 101–103
 * (Deluxe), a guest, a reservation arriving today with one unassigned
 * Deluxe line, a task on room 101, an activity with a booking, and a draft
 * knowledge article. Names carry `$marker`, so an isolation test can tell
 * whose data a result contains.
 *
 * @return array<string, mixed>
 */
function aatSeed(string $marker = 'Alpha', string $timezone = 'UTC'): array
{
    [$hotel, $admin, $type] = aatHotel($timezone);
    $hotel->update(['name' => "{$marker} Hotel"]);

    $rooms = collect(['101', '102', '103'])->mapWithKeys(fn (string $number) => [$number => aatRoom($hotel, $type, $number)]);
    $guest = aatGuest($hotel, ['first_name' => $marker, 'last_name' => "{$marker}son"]);
    $reservation = aatReservation($hotel, $type, [null], ['guest_id' => $guest->id]);
    $task = Task::create([
        'hotel_id' => $hotel->id,
        'room_id' => $rooms['101']->id,
        'title' => "{$marker} task",
        'created_by' => 'manual',
        'status' => 'pending',
        'priority' => 'normal',
    ]);
    $activity = abActivity($hotel, ['name' => "{$marker} tour"]);
    $booking = abBook($hotel, $activity, now($hotel->timezone)->addDays(3)->toDateString(), 1, ['guest_id' => $guest->id]);
    $article = KnowledgeBaseArticle::create([
        'hotel_id' => $hotel->id,
        'title' => "{$marker} article",
        'content' => "{$marker} knowledge.",
        'status' => 'draft',
    ]);
    $recommendation = Recommendation::create([
        'hotel_id' => $hotel->id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'reason' => "{$marker} suggestion",
        'recommended_at' => now(),
    ]);

    return [
        'hotel' => $hotel->fresh(),
        'admin' => $admin->fresh(),
        'type' => $type,
        'marker' => $marker,
        'rooms' => $rooms,
        'guest' => $guest,
        'reservation' => $reservation,
        'code' => $reservation->reservation_id,
        'task' => $task,
        'activity' => $activity,
        'booking' => $booking,
        'article' => $article,
        'recommendation' => $recommendation,
    ];
}

/**
 * Valid arguments for each Admin AI tool against an `aatSeed()` hotel. Every
 * tool in AdminToolset must have an entry: the permission and isolation
 * datasets are built from this map, so a new tool without one fails them.
 *
 * @param  array<string, mixed>  $s
 * @return array<string, mixed>
 */
function aatArgs(string $tool, array $s): array
{
    $tz = $s['hotel']->timezone;

    return match ($tool) {
        'KnowledgeSearchTool' => ['query' => 'pool hours'],
        'GetGuestsTool' => ['search' => $s['guest']->last_name],
        'GetGuestTool' => ['guest' => $s['guest']->id],
        'GetGuestMessagesTool', 'GetRoomTypesTool', 'GetTaskCategoriesTool', 'GetHousekeepingBoardTool',
        'GetMaintenanceTool', 'GetActivitiesTool', 'GetStaffTool', 'GetHotelSettingsTool' => [],
        'GetReservationsTool' => ['code' => $s['code']],
        'GetReservationTool' => ['code' => $s['code']],
        'GetRoomsTool' => ['room_number' => '101'],
        'GetAvailabilityTool' => ['arrival_date' => now($tz)->toDateString(), 'departure_date' => now($tz)->addDay()->toDateString()],
        'GetStaysTool' => ['list' => 'arrivals'],
        'GetTasksTool' => ['room_number' => '101'],
        'GetBookingsTool' => ['guest' => $s['guest']->last_name],
        'GetRecommendationsForReviewTool' => ['guest' => $s['guest']->last_name],
        'DecideRecommendationTool' => ['recommendation_id' => $s['recommendation']->id, 'action' => 'approve'],
        'GetReportTool' => ['report' => 'dashboard'],
        'CreateGuestTool' => ['phone_number' => '+44 7700 '.random_int(100000, 999999), 'first_name' => 'New'],
        'UpdateGuestTool' => ['guest_id' => $s['guest']->id, 'nationality' => 'FR'],
        'CreateReservationTool' => [
            'guest_phone' => $s['guest']->phone_number,
            'rooms' => [['room_type' => $s['type']->name, 'quantity' => 1]],
            'arrival_date' => now($tz)->addDays(20)->toDateString(),
            'departure_date' => now($tz)->addDays(22)->toDateString(),
        ],
        'UpdateReservationTool' => ['code' => $s['code'], 'special_requests' => 'Late arrival'],
        'CancelReservationTool' => ['code' => $s['code']],
        'AssignRoomsTool' => ['code' => $s['code'], 'assignments' => [['room_number' => '102']]],
        'CheckInTool' => ['reservation_id' => $s['code']],
        'CheckOutTool' => ['reservation_id' => $s['code']],
        'CreateRoomTool' => ['room_number' => '901', 'room_type' => $s['type']->name],
        'SetHousekeepingStatusTool' => ['room_number' => '101', 'housekeeping_status' => 'dirty'],
        'SetRoomOutOfOrderTool' => ['room_number' => '103', 'reason' => 'Water leak'],
        'UpdateOutOfOrderTool' => ['room_number' => '103', 'reason' => 'Water leak, ceiling'],
        'ReturnRoomToServiceTool' => ['room_number' => '103'],
        'CreateTaskTool' => ['title' => 'Replace lamp', 'room_number' => '102'],
        'UpdateTaskTool' => ['task_id' => $s['task']->id, 'priority' => 'high'],
        'ReportTaskIssueTool' => ['task_id' => $s['task']->id, 'description' => 'Tap drips'],
        'CreateActivityTool' => ['name' => 'Kayak', 'price' => 10],
        'CreateActivityBookingTool' => ['guest' => $s['guest']->id, 'activity' => $s['activity']->name, 'scheduled_for' => now($tz)->addDays(5)->toDateString()],
        'UpdateBookingStatusTool' => ['booking' => $s['booking']->reference, 'status' => 'confirmed'],
        'DecideBookingCancellationTool' => ['booking' => $s['booking']->reference, 'decision' => 'decline', 'note' => 'Too late to cancel.'],
        'CreateKnowledgeArticleTool' => ['title' => 'Shuttle', 'content' => 'The shuttle leaves at 9:00.', 'draft' => true],
        'UpdateKnowledgeArticleTool' => ['article_id' => $s['article']->id, 'title' => 'Renamed article'],
        default => throw new RuntimeException("aatArgs() has no arguments for {$tool}: add them."),
    };
}

/**
 * A fingerprint of one hotel's operational rows: how many, and the latest
 * change. Equal before and after means the hotel's data did not change.
 *
 * @return array<string, array{0: int, 1: mixed}>
 */
function aatFingerprint(Hotel $hotel): array
{
    $tables = ['guests', 'reservations', 'reservation_rooms', 'rooms', 'stays', 'tasks', 'bookings', 'activities', 'knowledge_base_articles', 'room_types'];

    return collect($tables)->mapWithKeys(fn (string $table) => [$table => [
        DB::table($table)->where('hotel_id', $hotel->id)->count(),
        DB::table($table)->where('hotel_id', $hotel->id)->max('updated_at'),
    ]])->all();
}

/**
 * Send one advisor chat request as the seeded hotel's admin.
 */
function aatChat($test, array $seed, array $payload)
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($seed['admin'], 'sanctum')->postJson('/api/ai-advisor/chat', $payload);
}

/*
|--------------------------------------------------------------------------
| Recommendation approval and proactive Concierge (SPEC 010)
|--------------------------------------------------------------------------
*/

/**
 * A hotel in a UTC+3 timezone, so "today" and quiet hours differ from UTC.
 */
function rapHotel(string $timezone = 'Asia/Riyadh'): Hotel
{
    return avHotel($timezone)->fresh();
}

function rapAdmin(Hotel $hotel): User
{
    return $hotel->owner;
}

/**
 * @param  list<Permission>  $permissions
 */
function rapEmployee(Hotel $hotel, array $permissions = []): User
{
    return fdEmployee($hotel, $permissions);
}

/**
 * A guest reachable on WhatsApp, in-house since yesterday (hotel time) and
 * leaving in four days, with one stay per room number.
 *
 * @param  array<string, mixed>  $guest
 * @return array{0: Guest, 1: Reservation, 2: Stay}
 */
function rapInHouseStay(Hotel $hotel, array $guest = [], ?array $roomNumbers = null): array
{
    $guest = gsGuest($hotel, ['preferred_language' => 'en', ...$guest]);
    $type = avType($hotel, 'Deluxe '.Str::random(4));
    $roomNumbers ??= [(string) random_int(1000, 9999)];
    $rooms = array_map(fn (string $number) => aatRoom($hotel, $type, $number), $roomNumbers);

    $reservation = ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'reservation_id' => 'RES-'.strtoupper(Str::random(8)),
        'arrival_date' => now($hotel->timezone)->subDay()->toDateString(),
        'departure_date' => now($hotel->timezone)->addDays(4)->toDateString(),
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        'reservation_value' => 900,
    ], array_map(fn (Room $room) => ['room_type_id' => $type->id, 'room_id' => $room->id], $rooms));

    $stays = fdStays($reservation);

    foreach ($stays as $stay) {
        app(StayLifecycleService::class)->checkIn($stay);
        $stay->forceFill(['checked_in_at' => now()->subDay()])->saveQuietly();
    }

    return [$guest->fresh(), $reservation->fresh(), $stays[0]->fresh()];
}

function rapActivity(Hotel $hotel, string $name = 'Snorkeling'): Activity
{
    return abActivity($hotel, ['name' => $name, 'description' => "{$name} with a guide."]);
}

/**
 * A recommendation for this reservation, approved unless told otherwise.
 *
 * @param  array<string, mixed>  $overrides
 */
function rapRecommendation(Reservation $reservation, Activity $activity, string $status = 'approved', array $overrides = []): Recommendation
{
    $recommendation = Recommendation::create([
        'hotel_id' => $reservation->hotel_id,
        'reservation_id' => $reservation->id,
        'activity_id' => $activity->id,
        'reason' => 'Suits this guest',
        'predicted_confidence' => 0.8,
        'priority' => 1,
        'recommended_at' => now()->subHour(),
        'source' => 'staff_request',
    ]);
    // Overrides may name columns no client can write (delivered_at,
    // pitch_decision_id), so they are forced.
    $recommendation->forceFill(['status' => $status, ...$overrides])->saveQuietly();

    return $recommendation->fresh('activity');
}

/**
 * A guest message at `$at`, which opens the 24-hour WhatsApp window.
 */
function rapInbound(Guest $guest, CarbonInterface $at): WhatsAppInboundMessage
{
    return gsInbound(PhoneNumber::digits($guest->phone_number), $at);
}

function rapRequest($test, User $user, string $method, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->json($method, $uri, $payload);
}

/**
 * Pitching on, the WhatsApp API faked, and the turn classifier answering
 * with `$opening`, quoting `$quote` from the guest's message.
 */
function rapPitchingOn(string $opening = 'evening_plans', string $quote = 'this evening'): void
{
    config([
        'pitching.enabled' => true,
        'services.whatsapp.phone_number_id' => 'test-phone-number-id',
        'services.whatsapp.access_token' => 'test-access-token',
    ]);
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
    rapClassifierSays($opening, $quote);
}

function rapClassifierSays(string $opening = 'evening_plans', string $quote = 'this evening'): void
{
    TurnSignalAgent::fake(fn () => [
        'complaint' => false,
        'opening' => $opening,
        'interest_category_id' => null,
        'evidence_quote' => $quote,
    ]);
}

/**
 * One guest turn's pitching decision, as the WhatsApp job makes it.
 */
function rapTurn(Guest $guest, Reservation $reservation, string $message = 'Any plans for this evening?'): PitchTurn
{
    return app(PitchCoordinator::class)->begin($guest, $guest->hotel, $reservation, $message, null);
}

/**
 * Stage the turn's offer through the pitch tool and deliver it, as a sent
 * reply would: the guest has now been pitched it.
 */
function rapPitch(Guest $guest, Reservation $reservation, string $message = 'Any plans for this evening?', string $words = 'this evening'): PitchTurn
{
    $turn = rapTurn($guest, $reservation, $message);
    $result = (string) (new PitchActivityTool($turn, $reservation->stay))->handle(new Request(['guest_words' => $words]));

    if (! str_starts_with($result, 'Staged')) {
        throw new RuntimeException("Nothing was pitched: {$result}");
    }

    app(PitchCoordinator::class)->delivered($guest, $turn->decision->decided_at->copy()->subSecond(), 'How about '.$turn->staged()->name.'?');

    return $turn;
}

/**
 * The guest's answer to a pitch, recorded the way the Concierge does.
 */
function rapGuestSays(PitchTurn $turn, Reservation $reservation, string $action): void
{
    EventLogger::asAiAgent(fn () => (new UpdateRecommendationTool($reservation))->handle(new Request([
        'recommendation_id' => $turn->staged()->recommendationId,
        'action' => $action,
        'evidence_quote' => 'no thanks',
        'confidence' => 0.9,
    ])));
}

/**
 * Proactive messaging switched on for the hotel, with any settings changed.
 *
 * @param  array<string, mixed>  $settings
 */
function rapProactiveOn(Hotel $hotel, array $settings = []): Hotel
{
    $hotel->forceFill(['proactive_settings' => ProactiveSettings::merge(
        ProactiveSettings::defaults(),
        ['enabled' => true, ...$settings],
    )])->save();

    return $hotel->fresh();
}

/**
 * Run the trigger sweep, which also sends what is due (the test queue is sync).
 */
function rapSweep(): void
{
    app()->call([new EvaluateProactiveTriggersJob, 'handle']);
}

/**
 * @return Collection<int, ProactiveMessage>
 */
function rapProactiveRows(Guest $guest)
{
    return ProactiveMessage::withoutGlobalScope('hotel')->where('guest_id', $guest->id)->orderBy('created_at')->get();
}
