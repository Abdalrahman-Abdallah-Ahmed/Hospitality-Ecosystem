<?php

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\EventLog;
use App\Models\Hotel;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function acGet($test, User $user, array $query): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])
        ->actingAs($user, 'sanctum')
        ->getJson('/api/availability?'.http_build_query($query));
}

function acEmployee(Hotel $hotel, ?array $permissions = null): User
{
    $role = $permissions === null ? null : StaffRole::create([
        'hotel_id' => $hotel->id,
        'name' => 'Role '.uniqid(),
        'permissions' => array_map(fn (Permission $permission) => $permission->value, $permissions),
    ]);

    return User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role?->id]);
}

function acDates(int $fromDays, int $toDays): array
{
    return ['arrival_date' => now()->addDays($fromDays)->toDateString(), 'departure_date' => now()->addDays($toDays)->toDateString()];
}

it('returns the per-type, per-night grid in the documented shape', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 4);
    avRooms($hotel, $deluxe, 1, 'maintenance');
    $dates = acDates(10, 13);
    avBook($hotel, $deluxe, now()->addDays(11)->toDateString(), now()->addDays(12)->toDateString(), units: 2);

    $response = acGet($this, $hotel->owner, $dates)->assertOk();

    $response->assertJsonPath('message', 'Availability retrieved successfully.')
        ->assertJsonPath('body.arrival_date', $dates['arrival_date'])
        ->assertJsonPath('body.departure_date', $dates['departure_date'])
        ->assertJsonPath('body.nights', 3)
        ->assertJsonPath('body.room_types.0.room_type', [
            'id' => $deluxe->id,
            'name' => 'Deluxe',
            'max_occupancy' => 3,
            'adult_capacity' => 2,
            'child_capacity' => 1,
            'is_active' => true,
        ])
        ->assertJsonPath('body.room_types.0.bookable_for_stay', 2)
        ->assertJsonPath('body.room_types.0.nights.1', [
            'date' => now()->addDays(11)->toDateString(),
            'total' => 5,
            'out_of_order' => 1,
            'booked' => 2,
            'sellable' => 2,
            'overbooked' => 0,
        ]);

    expect(array_column($response->json('body.room_types.0.nights'), 'sellable'))->toBe([4, 2, 4]);
});

it('limits the result to the named room types, inactive ones included', function () {
    $hotel = avHotel();
    avType($hotel, 'Deluxe');
    $retired = avType($hotel, 'Retired', active: false);

    $response = acGet($this, $hotel->owner, [...acDates(1, 2), 'room_type_ids' => [$retired->id]])->assertOk();

    expect(collect($response->json('body.room_types'))->pluck('room_type.name')->all())->toBe(['Retired']);
});

it('rejects invalid ranges', function (array $dates, string $field) {
    $hotel = avHotel();

    acGet($this, $hotel->owner, $dates)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'departure not after arrival' => [fn () => acDates(5, 5), 'departure_date'],
    'more than 90 nights' => [fn () => acDates(1, 92), 'departure_date'],
    'arrival in the past' => [fn () => acDates(-2, 1), 'arrival_date'],
    'missing dates' => [fn () => [], 'arrival_date'],
]);

it('gives the same answer for an unknown, deleted or other hotel\'s room type', function () {
    $hotel = avHotel();
    $other = avHotel();
    $foreign = avType($other, 'Foreign');
    $deleted = avType($hotel, 'Gone');
    $deleted->delete();

    foreach ([$foreign->id, $deleted->id, '9f1c0000-0000-4000-8000-000000000000'] as $id) {
        acGet($this, $hotel->owner, [...acDates(1, 2), 'room_type_ids' => [$id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more of the selected room types are invalid.');
    }
});

it('makes a super admin name the hotel', function () {
    $hotel = avHotel();
    avType($hotel, 'Deluxe');
    $superAdmin = User::factory()->role(UserRole::SUPER_ADMIN)->create();

    acGet($this, $superAdmin, acDates(1, 2))->assertForbidden();

    acGet($this, $superAdmin, [...acDates(1, 2), 'hotel_id' => $hotel->id])
        ->assertOk()
        ->assertJsonPath('body.room_types.0.room_type.name', 'Deluxe');
});

it('lets an employee without a staff role look availability up by default', function () {
    $hotel = avHotel();

    acGet($this, acEmployee($hotel), acDates(1, 2))->assertOk();
});

it('forbids an employee whose role does not grant availability.view', function () {
    $hotel = avHotel();

    acGet($this, acEmployee($hotel, [Permission::RESERVATIONS_VIEW]), acDates(1, 2))->assertForbidden();
    acGet($this, acEmployee($hotel, [Permission::AVAILABILITY_VIEW]), acDates(1, 2))->assertOk();
});

it('writes nothing to the audit log', function () {
    $hotel = avHotel();
    avRooms($hotel, avType($hotel, 'Deluxe'), 2);
    $before = EventLog::withoutGlobalScopes()->count();

    acGet($this, $hotel->owner, acDates(1, 30))->assertOk();

    expect(EventLog::withoutGlobalScopes()->count())->toBe($before);
});

it('returns one cell per type per night, types by name, nights without gaps', function () {
    $hotel = avHotel();
    foreach (['Suite', 'Deluxe', 'Family', 'Standard'] as $name) {
        avRooms($hotel, avType($hotel, $name), 2);
    }

    $response = acGet($this, $hotel->owner, acDates(1, 15))->assertOk();
    $rows = $response->json('body.room_types');

    expect(collect($rows)->pluck('room_type.name')->all())->toBe(['Deluxe', 'Family', 'Standard', 'Suite'])
        ->and(collect($rows)->sum(fn ($row) => count($row['nights'])))->toBe(56);

    $dates = array_column($rows[0]['nights'], 'date');
    foreach ($dates as $i => $date) {
        expect($date)->toBe(now()->addDays(1 + $i)->toDateString());
    }
});

it('shows an overbooked night as zero sellable and how far over it is', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    avBook($hotel, $deluxe, now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString(), units: 2);

    acGet($this, $hotel->owner, acDates(3, 4))
        ->assertOk()
        ->assertJsonPath('body.room_types.0.nights.0.sellable', 0)
        ->assertJsonPath('body.room_types.0.nights.0.overbooked', 1);
});

it('returns zeros, not an error, for a type with no rooms', function () {
    $hotel = avHotel();
    avType($hotel, 'Empty');

    acGet($this, $hotel->owner, acDates(1, 3))
        ->assertOk()
        ->assertJsonPath('body.room_types.0.bookable_for_stay', 0)
        ->assertJsonPath('body.room_types.0.nights.1.total', 0);
});
