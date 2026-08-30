<?php

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\AiInsights;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Team;
use App\Models\User;
use App\Models\WhatsAppDevice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
        Room::class => fn (Hotel $hotel) => Room::create([
            'hotel_id' => $hotel->id,
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

it('sees nothing at all for a restricted user with no accessible hotel', function () {
    $hotel = makeHotel();
    Room::create(['hotel_id' => $hotel->id, 'room_number' => '101']);

    $hotellessAdmin = User::factory()->role(UserRole::ADMIN)->create(['hotel_id' => null]);

    TenantContext::setHotelIds($hotellessAdmin->accessibleHotelIds());

    expect(Room::query()->count())->toBe(0);
});

it('leaves a super admin unrestricted', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();
    Room::create(['hotel_id' => $hotelA->id, 'room_number' => '101']);
    Room::create(['hotel_id' => $hotelB->id, 'room_number' => '201']);

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

    Room::create(['hotel_id' => $hotelA->id, 'room_number' => '101']);
    Room::create(['hotel_id' => $hotelB->id, 'room_number' => '201']);

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
        $room = Room::create(['room_number' => '101']);

        expect($room->hotel_id)->toBe($hotel->id);
    });
});

it('does not override an explicitly provided hotel_id on create', function () {
    $hotelA = makeHotel();
    $hotelB = makeHotel();

    TenantContext::runForHotel($hotelA->id, function () use ($hotelB) {
        $room = Room::create(['hotel_id' => $hotelB->id, 'room_number' => '101']);

        expect($room->hotel_id)->toBe($hotelB->id);
    });
});
