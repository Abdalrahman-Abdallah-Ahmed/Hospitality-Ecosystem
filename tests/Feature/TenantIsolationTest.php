<?php

use App\Ai\Tools\CheckInTool;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Ai\Tools\GetAvailabilityTool;
use App\Ai\Tools\GetGuestAvailabilityTool;
use App\Ai\Tools\GetStaysTool;
use App\Ai\Tools\RequestRoomChangeTool;
use App\Enums\AttributionMethod;
use App\Enums\OutcomeType;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\AiInsights;
use App\Models\Booking;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\PitchDecision;
use App\Models\ProactiveMessage;
use App\Models\Recommendation;
use App\Models\RecommendationOutcome;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Services\BookingCancellationService;
use App\Services\BookingService;
use App\Services\HousekeepingService;
use App\Services\RecommendationOutcomeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\Request as ToolRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function makeHotel(): Hotel
{
    $owner = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Hotel '.uniqid(),
        'slug' => 'hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

/**
 * One row-factory per tenant-owned model, each producing a row that belongs
 * to the given hotel. Covers every model using the BelongsToHotel trait —
 * not just the eight the plan named explicitly, since Team, ActivityCategory,
 * TaskCategory, Recommendation, KnowledgeChunk, and WhatsAppDevice also carry
 * a hotel_id and are just as exposed to cross-tenant leakage if unscoped.
 */
function tenantOwnedModelFactories(): array
{
    return [
        Guest::class => fn (Hotel $hotel) => Guest::create([
            'hotel_id' => $hotel->id,
            'external_id' => 'ext-'.uniqid(),
            'channel' => 'booking_com',
        ]),
        Reservation::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return Reservation::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'reservation_id' => 'RES-'.uniqid(),
                'arrival_date' => '2026-09-01',
                'departure_date' => '2026-09-04',
                'status' => 'confirmed',
            ]);
        },
        ReservationRoom::class => fn (Hotel $hotel) => createReservationWithRooms($hotel, [[]])
            ->reservationRooms()->first(),
        Room::class => fn (Hotel $hotel) => Room::create([
            'hotel_id' => $hotel->id,
            'room_type_id' => roomTypeIdFor($hotel),
            'room_number' => '101',
        ]),
        Stay::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return Stay::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'planned_arrival_date' => '2026-09-01',
                'planned_departure_date' => '2026-09-04',
            ]);
        },
        // Pitch decisions quote the guest's own words, so another hotel
        // seeing them is a privacy leak as well as a tenancy one.
        PitchDecision::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return PitchDecision::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'eligible' => false,
                'gates' => [],
                'opening_quote' => 'what can we do tonight',
                'rules_version' => '1.0',
                'decided_at' => now(),
            ]);
        },
        ActivityCategory::class => fn (Hotel $hotel) => ActivityCategory::create([
            'hotel_id' => $hotel->id,
            'name' => 'Category',
        ]),
        Activity::class => fn (Hotel $hotel) => Activity::create([
            'hotel_id' => $hotel->id,
            'name' => 'Diving',
            'price' => 10,
        ]),
        Task::class => fn (Hotel $hotel) => Task::create([
            'hotel_id' => $hotel->id,
            'title' => 'Clean room',
        ]),
        AiInsights::class => fn (Hotel $hotel) => AiInsights::create([
            'hotel_id' => $hotel->id,
            'title' => 'Insight',
            'description' => 'Description',
            'category' => 'general',
            'insight_type' => 'general',
        ]),
        KnowledgeBaseArticle::class => fn (Hotel $hotel) => KnowledgeBaseArticle::create([
            'hotel_id' => $hotel->id,
            'title' => 'Article',
            'content' => 'Content',
        ]),
        HotelPolicy::class => fn (Hotel $hotel) => HotelPolicy::create([
            'hotel_id' => $hotel->id,
            'title' => 'Policy',
            'content' => 'Content',
        ]),
        TaskCategory::class => fn (Hotel $hotel) => TaskCategory::create([
            'hotel_id' => $hotel->id,
            'name' => 'Housekeeping',
        ]),
        Team::class => fn (Hotel $hotel) => Team::create([
            'hotel_id' => $hotel->id,
            'name' => 'Team '.uniqid(),
        ]),
        Recommendation::class => function (Hotel $hotel) {
            $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);

            return Recommendation::create([
                'hotel_id' => $hotel->id,
                'activity_id' => $activity->id,
            ]);
        },
        Transaction::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return Transaction::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'item_name' => 'Dive trip',
                'line_total' => 120,
                'currency' => 'USD',
                'transacted_at' => '2026-09-01 12:00:00',
                'business_date' => '2026-09-01',
                'source_system' => 'import',
                'external_reference' => 'ext-'.uniqid(),
            ]);
        },
        Booking::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return app(BookingService::class)->create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'item_name' => 'Sunset dive',
                'charge_model' => 'pay_on_site',
                'origin' => 'guest_request',
            ]);
        },
        RecommendationOutcome::class => function (Hotel $hotel) {
            $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);
            $recommendation = Recommendation::create([
                'hotel_id' => $hotel->id,
                'activity_id' => $activity->id,
            ]);

            return app(RecommendationOutcomeService::class)->record(
                $recommendation,
                OutcomeType::DELIVERED,
                AttributionMethod::STAFF,
            );
        },
        EventLog::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return EventLog::create([
                'hotel_id' => $hotel->id,
                'event_type' => 'guest.created',
                'subject_type' => $guest->getMorphClass(),
                'subject_id' => $guest->id,
                'actor_kind' => 'system',
                'occurred_at' => now(),
            ]);
        },
        KnowledgeChunk::class => function (Hotel $hotel) {
            $policy = HotelPolicy::create(['hotel_id' => $hotel->id, 'title' => 'Policy', 'content' => 'Content']);

            return KnowledgeChunk::create([
                'chunkable_type' => HotelPolicy::class,
                'chunkable_id' => $policy->id,
                'hotel_id' => $hotel->id,
                'content' => 'Chunk content',
                'embedding' => array_fill(0, 1536, 0.0),
            ]);
        },
        KnowledgeDocument::class => fn (Hotel $hotel) => KnowledgeDocument::create([
            'hotel_id' => $hotel->id,
            'title' => 'House Rules',
            'original_filename' => 'rules.pdf',
            'path' => 'knowledge/'.$hotel->id.'/doc/rules.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'content_hash' => hash('sha256', uniqid()),
        ]),
        ProactiveMessage::class => function (Hotel $hotel) {
            $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

            return tap((new ProactiveMessage)->forceFill([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'trigger' => 'first_morning',
                'event_key' => 'first_morning:'.uniqid(),
                'status' => 'scheduled',
                'due_at' => now(),
                'valid_until' => now()->addHours(3),
            ]))->save();
        },
        WhatsAppDevice::class => function (Hotel $hotel) {
            $user = User::factory()->role(UserRole::EMPLOYEE)->create();

            return WhatsAppDevice::create([
                'user_id' => $user->id,
                'hotel_id' => $hotel->id,
                'wa_user_id' => 'wa-'.uniqid(),
                'phone_number' => '2010'.random_int(1000000, 9999999),
                'status' => 'active',
            ]);
        },
    ];
}

it('never leaks another hotel\'s rows for every tenant-owned model', function (string $modelClass, Closure $factory) {
    $hotelA = makeHotel();
    $hotelB = makeHotel();

    $rowA = $factory($hotelA);
    $rowB = $factory($hotelB);

    TenantContext::runForHotel($hotelA->id, function () use ($modelClass, $rowA, $rowB) {
        $ids = $modelClass::query()->pluck('id');

        expect($ids)->toContain($rowA->id);
        expect($ids)->not->toContain($rowB->id);
    });

    // The row genuinely still exists — it's hidden from hotel A's context,
    // not deleted or corrupted.
    expect($modelClass::withoutGlobalScope('hotel')->find($rowB->id))->not->toBeNull();
})->with(fn () => (function () {
    foreach (tenantOwnedModelFactories() as $modelClass => $factory) {
        yield $modelClass => [$modelClass, $factory];
    }
})());

it('does not carry the tenant of one request into the next', function () {
    [$adminA, $hotelA] = wp5AdminWithHotel();
    [$adminB, $hotelB] = wp5AdminWithHotel();

    $bookingA = wp5Booking($hotelA, ['guest_id' => wp5Recommendation($hotelA)[1]->id]);
    $bookingB = wp5Booking($hotelB, ['guest_id' => wp5Recommendation($hotelB)[1]->id]);

    // TenantContext is process-static. Before ResolveTenant reset it, the
    // second request in a process still had the first one's tenant while
    // resolving its route-model binding, so the same cross-hotel lookup
    // answered 403 or 404 depending only on request order.
    $first = $this->withHeaders(wp5Headers())->actingAs($adminA, 'sanctum')
        ->getJson("/api/booking/{$bookingB->id}");
    $second = $this->withHeaders(wp5Headers())->actingAs($adminB, 'sanctum')
        ->getJson("/api/booking/{$bookingA->id}");

    expect($first->status())->toBe(403)
        ->and($second->status())->toBe($first->status());
});

it('sees nothing at all for a restricted user with no accessible hotel', function () {
    $hotel = makeHotel();
    Room::create(['hotel_id' => $hotel->id, 'room_type_id' => roomTypeIdFor($hotel), 'room_number' => '101']);

    $hotellessAdmin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => null]);

    TenantContext::setHotelIds($hotellessAdmin->accessibleHotelIds());

    expect(Room::query()->count())->toBe(0);
});

it('leaves a super admin unrestricted', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    Room::create(['hotel_id' => $hotelA->id, 'room_type_id' => roomTypeIdFor($hotelA), 'room_number' => '101']);
    Room::create(['hotel_id' => $hotelB->id, 'room_type_id' => roomTypeIdFor($hotelB), 'room_number' => '201']);

    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    expect($superAdmin->accessibleHotelIds())->toBeNull();

    TenantContext::setHotelIds($superAdmin->accessibleHotelIds());

    expect(Room::query()->count())->toBe(2);
});

it('gives a group admin every hotel in their group automatically', function () {
    $group = HotelGroup::create(['name' => 'Group', 'slug' => 'group-'.uniqid()]);

    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $hotelA->update(['hotel_group_id' => $group->id]);
    $hotelB->update(['hotel_group_id' => $group->id]);

    Room::create(['hotel_id' => $hotelA->id, 'room_type_id' => roomTypeIdFor($hotelA), 'room_number' => '101']);
    Room::create(['hotel_id' => $hotelB->id, 'room_type_id' => roomTypeIdFor($hotelB), 'room_number' => '201']);

    $director = User::factory()->role(UserRole::ADMIN)->create([
        'hotel_id' => null,
        'hotel_group_id' => $group->id,
        'group_role' => 'group_admin',
    ]);

    $accessibleIds = $director->accessibleHotelIds();

    expect($accessibleIds)->toContain($hotelA->id, $hotelB->id);

    TenantContext::setHotelIds($accessibleIds);

    expect(Room::query()->count())->toBe(2);
});

it('stamps hotel_id automatically on create when the model omits it', function () {
    $hotel = makeHotel();

    TenantContext::runForHotel($hotel->id, function () use ($hotel) {
        $room = Room::create(['room_number' => '101', 'room_type_id' => roomTypeIdFor($hotel)]);

        expect($room->hotel_id)->toBe($hotel->id);
    });
});

it('does not override an explicitly provided hotel_id on create', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();

    TenantContext::runForHotel($hotelA->id, function () use ($hotelB) {
        $room = Room::create(['hotel_id' => $hotelB->id, 'room_type_id' => roomTypeIdFor($hotelB), 'room_number' => '101']);

        expect($room->hotel_id)->toBe($hotelB->id);
    });
});

// Reservation rooms: lines, line filters and cross-hotel references

it('never shows or accepts another hotel\'s room types and rooms on reservation lines', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    $adminB = User::where('hotel_id', $hotelB->id)->firstOrFail();

    $typeA = roomTypeIdFor($hotelA);
    $roomA = Room::create(['hotel_id' => $hotelA->id, 'room_type_id' => $typeA, 'room_number' => '101']);
    $reservationA = createReservationWithRooms($hotelA, [['room_id' => $roomA->id]]);
    $guestB = Guest::create(['hotel_id' => $hotelB->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);

    // Reading hotel A's reservation (and its lines) is refused.
    $this->withHeaders(wp5Headers())->actingAs($adminB, 'sanctum')
        ->getJson("/api/reservation/{$reservationA->id}")
        ->assertStatus(403);

    // Filtering by hotel A's type or room finds nothing.
    foreach (['room_type_id' => $typeA, 'room_id' => $roomA->id] as $filter => $value) {
        $this->withHeaders(wp5Headers())->actingAs($adminB, 'sanctum')
            ->getJson("/api/reservation?filter[{$filter}]={$value}")
            ->assertOk()
            ->assertJsonCount(0, 'body.data');
    }

    // Booking hotel A's type or room is rejected, with no hint either exists.
    $payload = fn (array $line) => [
        'hotel_id' => $hotelB->id,
        'guest_id' => $guestB->id,
        'reservation_id' => 'RES-'.uniqid(),
        'arrival_date' => '2026-10-01',
        'departure_date' => '2026-10-04',
        'rooms' => [$line],
    ];

    $this->withHeaders(wp5Headers())->actingAs($adminB, 'sanctum')
        ->postJson('/api/reservation', $payload(['room_type_id' => $typeA]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.0.room_type_id' => 'The selected room type is not available.']);

    $this->withHeaders(wp5Headers())->actingAs($adminB, 'sanctum')
        ->postJson('/api/reservation', $payload(['room_type_id' => roomTypeIdFor($hotelB), 'room_id' => $roomA->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rooms.0.room_id' => 'The selected room is not available.']);

    expect(ReservationRoom::withoutGlobalScope('hotel')->where('hotel_id', $hotelB->id)->count())->toBe(0);
});

it('never counts or reveals another hotel\'s rooms and bookings in availability', function () {
    $hotelA = avHotel();
    $hotelB = avHotel();
    $typeA = avType($hotelA, 'Deluxe');
    avRooms($hotelA, $typeA, 2);
    $typeB = avType($hotelB, 'Deluxe');
    avRooms($hotelB, $typeB, 9);
    $arrival = now()->addDays(5)->toDateString();
    $departure = now()->addDays(6)->toDateString();
    avBook($hotelB, $typeB, $arrival, $departure, units: 4);

    $lookup = fn (array $extra = []) => $this->withHeaders(wp5Headers())->actingAs($hotelA->owner, 'sanctum')
        ->getJson('/api/availability?'.http_build_query(['arrival_date' => $arrival, 'departure_date' => $departure, ...$extra]));

    $lookup()->assertOk()
        ->assertJsonCount(1, 'body.room_types')
        ->assertJsonPath('body.room_types.0.room_type.id', $typeA->id)
        ->assertJsonPath('body.room_types.0.nights.0.total', 2)
        ->assertJsonPath('body.room_types.0.nights.0.booked', 0);

    // Naming hotel B's type is rejected like any unknown id.
    $lookup(['room_type_ids' => [$typeB->id]])
        ->assertStatus(422)
        ->assertJsonPath('message', 'One or more of the selected room types are invalid.');
});

it('never lets an availability AI tool built for one hotel see another hotel\'s room types or bookings', function () {
    $hotelA = avHotel();
    $hotelB = avHotel();
    avRooms($hotelA, avType($hotelA, 'Deluxe'), 1);
    $typeB = avType($hotelB, 'Penthouse');
    avRooms($hotelB, $typeB, 4);
    avBook($hotelB, $typeB, now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString(), units: 2);
    $dates = ['arrival_date' => now()->addDays(3)->toDateString(), 'departure_date' => now()->addDays(4)->toDateString()];

    $admin = (string) (new GetAvailabilityTool($hotelA, $hotelA->owner))->handle(new Request($dates));
    $named = (string) (new GetAvailabilityTool($hotelA, $hotelA->owner))->handle(new Request([...$dates, 'room_type' => 'Penthouse']));
    $guest = (string) (new GetGuestAvailabilityTool($hotelA))->handle(new Request([...$dates, 'room_type' => 'Penthouse']));

    expect($admin)->not->toContain('Penthouse')->not->toContain($typeB->id)
        ->and(collect(json_decode($admin, true)['room_types'])->pluck('room_type.name')->all())->toBe(['Deluxe'])
        ->and($named)->toBe('This hotel has no room type called "Penthouse".')
        ->and(collect(json_decode($guest, true)['room_types'])->pluck('name')->all())->toBe(['Deluxe']);
});

it('never shows or lets anyone act on another hotel\'s stays', function () {
    [$hotelA, $typeA, [$roomA]] = fdHotel();
    [$hotelB, $typeB, [$roomB]] = fdHotel();
    $reservationB = fdBook($hotelB, $typeB, [$roomB->id]);
    [$stayB] = fdStays($reservationB);
    fdBook($hotelA, $typeA, [$roomA->id]);
    $adminA = $hotelA->owner;

    // Lists show only the admin's own hotel.
    foreach (['/api/stays/arrivals', '/api/stays'] as $uri) {
        $ids = collect(fdGet($this, $adminA, $uri)->assertOk()->json($uri === '/api/stays' ? 'body.data' : 'body.stays'))->pluck('id');
        expect($ids)->not->toContain($stayB->id);
    }

    // Another hotel's stay or reservation is refused (the same-hotel policy
    // check, like every other resource), and nothing changes.
    fdGet($this, $adminA, "/api/stays/{$stayB->id}")->assertForbidden();
    fdPost($this, $adminA, "/api/stays/{$stayB->id}/check-in")->assertForbidden();
    fdPost($this, $adminA, "/api/stays/{$stayB->id}/check-out")->assertForbidden();
    fdPost($this, $adminA, "/api/reservation/{$reservationB->id}/check-in")->assertForbidden();
    fdPost($this, $adminA, "/api/reservation/{$reservationB->id}/check-out")->assertForbidden();

    // Naming another hotel's room at check-in is refused like an unknown room.
    [$stayA] = fdStays(fdBook($hotelA, $typeA, [null]));
    fdPost($this, $adminA, "/api/stays/{$stayA->id}/check-in", ['room_id' => $roomB->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["stays.{$stayA->id}.room_id" => 'The selected room is not available.']);

    // The Admin AI's tools only ever see their own hotel.
    $tools = [new GetStaysTool($hotelA, $adminA), new CheckInTool($hotelA, $adminA)];
    expect((string) $tools[0]->handle(new ToolRequest(['list' => 'arrivals'])))->not->toContain($reservationB->reservation_id)
        ->and((string) $tools[1]->handle(new ToolRequest(['reservation_id' => $reservationB->reservation_id])))->toBe('This hotel has no reservation with that code.');

    expect($stayB->fresh()->status->value)->toBe('expected');
});

it('keeps one hotel out of another hotel housekeeping and maintenance (FR-034)', function () {
    [$hotelA, $roomA, $cleaningA] = hkVacatedRoom($this);
    [$hotelB, $roomB, $cleaningB] = hkVacatedRoom($this);
    $adminA = $hotelA->owner;

    // Another hotel's room or task cannot be acted on.
    hkPut($this, $adminA, "/api/room/{$roomB->id}/housekeeping-status", ['housekeeping_status' => 'clean', 'reason' => 'x'])->assertForbidden();
    fdPost($this, $adminA, "/api/room/{$roomB->id}/out-of-order", ['reason' => 'x'])->assertForbidden();
    hkPatch($this, $adminA, "/api/room/{$roomB->id}/out-of-order", ['reason' => 'x'])->assertForbidden();
    fdPost($this, $adminA, "/api/room/{$roomB->id}/return-to-service")->assertForbidden();
    fdPost($this, $adminA, "/api/task/{$cleaningB->id}/inspection", ['result' => 'pass'])->assertForbidden();
    fdPost($this, $adminA, "/api/task/{$cleaningB->id}/issues", ['description' => 'x'])->assertForbidden();
    fdPost($this, $adminA, "/api/room/{$roomA->id}/out-of-order", ['reason' => 'x', 'task_id' => $cleaningB->id])->assertForbidden();

    // Lists show only the caller's own hotel.
    fdPost($this, $hotelB->owner, "/api/task/{$cleaningB->id}/issues", ['description' => 'Their leak'])->assertCreated();
    $board = collect(fdGet($this, $adminA, '/api/housekeeping/board')->assertOk()->json('body.rooms'))->pluck('room.id');
    $maintenance = collect(fdGet($this, $adminA, '/api/maintenance/tasks')->assertOk()->json('body.data'))->pluck('room.id');
    expect($board)->toContain($roomA->id)->not->toContain($roomB->id)
        ->and($maintenance)->not->toContain($roomB->id);

    // Settings cannot point at another hotel's teams.
    hkPut($this, $adminA, "/api/hotel/{$hotelA->id}", ['maintenance_team_id' => $hotelB->maintenance_team_id])->assertForbidden();

    // Start of day for one hotel never touches another.
    app(HousekeepingService::class)->startDay($hotelA, now()->toDateString());
    expect($roomB->fresh()->housekeeping_status->value)->toBe('dirty')
        ->and(Task::withoutGlobalScope('hotel')->where('room_id', $roomB->id)->count())->toBe(2);
});

it('keeps one hotel out of another hotel activity availability, bookings and cancellation requests (FR-031)', function () {
    $this->travelTo('2026-10-05 09:00:00');
    $hotelA = avHotel();
    $hotelB = avHotel();
    $adminA = User::find($hotelA->owner_id);
    $activityA = abActivity($hotelA, ['daily_capacity' => 5]);
    $activityB = abActivity($hotelB, ['daily_capacity' => 5]);
    abBook($hotelA, $activityA, '2026-10-09', 2);
    $bookingB = abBook($hotelB, $activityB, '2026-10-09', 3);
    app(BookingCancellationService::class)
        ->request($bookingB, Guest::withoutGlobalScope('hotel')->find($bookingB->guest_id), 'B guest');

    fdGet($this, $adminA, "/api/activity/{$activityB->id}/availability?from=2026-10-09")->assertForbidden();
    abPatch($this, $adminA, "/api/booking/{$bookingB->id}", ['notes' => 'x'])->assertForbidden();
    fdPost($this, $adminA, "/api/booking/{$bookingB->id}/cancellation-request/approve")->assertForbidden();
    fdPost($this, $adminA, "/api/booking/{$bookingB->id}/cancellation-request/decline", ['note' => 'x'])->assertForbidden();

    expect(fdGet($this, $adminA, '/api/booking/cancellation-requests')->json('body.data'))->toBe([])
        // A's places never count B's bookings.
        ->and(fdGet($this, $adminA, "/api/activity/{$activityA->id}/availability?from=2026-10-09")->json('body.days.0.booked'))->toBe(2)
        ->and($bookingB->fresh()->notes)->toBeNull()
        ->and($bookingB->fresh()->status->value)->toBe('pending');
});

it('keeps one hotel out of another hotel guest requests and their notices (SPEC-007 FR-037)', function () {
    $whatsApp = gsFakeWhatsApp();
    [$adminA, $hotelA] = gsHotel();
    [$adminB, $hotelB] = gsHotel();
    $guestB = gsGuest($hotelB);
    $reservationB = gsInHouse($hotelB, $guestB);
    gsRunTool(new RequestRoomChangeTool($guestB, $hotelB, $reservationB), ['reason' => 'Noisy']);
    gsRunTool(new CreateGuestServiceRequestTool($guestB, $hotelB, $reservationB), ['kind' => 'maintenance_request', 'title' => 'AC', 'description' => 'Broken']);
    $roomChangeB = Task::withoutGlobalScope('hotel')->where('guest_signal', 'room_change_request')->sole();
    $maintenanceB = Task::withoutGlobalScope('hotel')->where('guest_signal', 'maintenance_request')->sole();
    hkSetTaskStatus($this, $adminB, $maintenanceB, 'cancelled')->assertOk();

    fdGet($this, $adminA, "/api/task/{$roomChangeB->id}")->assertForbidden();
    hkSetTaskStatus($this, $adminA, $roomChangeB, 'completed')->assertForbidden();

    expect(fdGet($this, $adminA, '/api/task?filter[guest_signal]=room_change_request')->assertOk()->json('body.data'))->toBe([])
        ->and(fdGet($this, $adminA, '/api/task?filter[guest_notice_status]=skipped')->assertOk()->json('body.data'))->toBe([])
        ->and($roomChangeB->fresh()->status->value)->toBe('pending')
        ->and($whatsApp->sent)->toBe([]);
});

it('keeps one hotel out of another hotel knowledge documents (SPEC-008 FR-040)', function () {
    knFakeEmbeddings();
    [$adminA] = knHotel();
    [, $hotelB] = knHotel();
    $documentB = knDocument($hotelB, ['title' => 'B house rules']);
    $deletedB = knDocument($hotelB, ['title' => 'B old menu']);
    $deletedB->delete();

    $call = fn (string $method, string $uri, array $payload = []) => knRequest($this, $adminA, $method, $uri, $payload);

    $call('GET', "/api/knowledge-documents/{$documentB->id}")->assertNotFound();
    $call('GET', "/api/knowledge-documents/{$documentB->id}/download")->assertNotFound();
    $call('PUT', "/api/knowledge-documents/{$documentB->id}", ['title' => 'Mine now'])->assertNotFound();
    $call('POST', "/api/knowledge-documents/{$documentB->id}/file")->assertStatus(422);
    $call('GET', "/api/knowledge-documents/{$documentB->id}/text")->assertNotFound();
    $call('PUT', "/api/knowledge-documents/{$documentB->id}/text", ['segments' => [['location' => 'Page 1', 'text' => 'x']]])->assertNotFound();
    $call('DELETE', "/api/knowledge-documents/{$documentB->id}/text")->assertNotFound();
    $call('POST', "/api/knowledge-documents/{$documentB->id}/reindex")->assertNotFound();
    $call('DELETE', "/api/knowledge-documents/{$documentB->id}")->assertNotFound();
    $call('POST', "/api/knowledge-documents/{$deletedB->id}/restore")->assertNotFound();

    expect($call('GET', '/api/knowledge-documents')->assertOk()->json('body.data'))->toBe([])
        ->and($call('GET', '/api/knowledge-documents/deleted')->assertOk()->json('body.data'))->toBe([])
        ->and($documentB->fresh())->title->toBe('B house rules')->deleted_at->toBeNull()
        ->and(KnowledgeDocument::withoutGlobalScope('hotel')->withTrashed()->find($deletedB->id)->trashed())->toBeTrue();
});

it('keeps one hotel out of another hotel recommendation approvals (SPEC-071 FR-039)', function () {
    $hotelA = rapHotel();
    $hotelB = rapHotel();
    [, $reservationB] = rapInHouseStay($hotelB);
    $recommendationB = rapRecommendation($reservationB, rapActivity($hotelB), 'pending_approval');
    $adminA = rapAdmin($hotelA);

    rapRequest($this, $adminA, 'POST', "/api/recommendation/{$recommendationB->id}/approve")->assertForbidden();
    rapRequest($this, $adminA, 'POST', "/api/recommendation/{$recommendationB->id}/reject")->assertForbidden();

    $bulk = rapRequest($this, $adminA, 'POST', '/api/recommendations/decide', ['action' => 'approve', 'ids' => [$recommendationB->id]])->assertOk();

    expect($bulk->json('body.decided'))->toBe([])
        ->and($bulk->json('body.skipped'))->toBe([['id' => $recommendationB->id, 'reason' => 'not_found']])
        ->and(rapRequest($this, $adminA, 'GET', '/api/recommendation?status=pending_approval')->json('body.data'))->toBe([])
        ->and($recommendationB->fresh()->status->value)->toBe('pending_approval');
});

it('keeps one hotel out of another hotel guest contact preferences (SPEC-073 FR-039)', function () {
    $hotelA = rapHotel();
    $hotelB = rapHotel();
    [$guestB] = rapInHouseStay($hotelB);

    rapRequest($this, rapAdmin($hotelA), 'PUT', "/api/guest/{$guestB->id}/contact-preference", ['proactive_opted_out' => true])->assertForbidden();

    expect($guestB->fresh()->proactive_opted_out_at)->toBeNull();
});
