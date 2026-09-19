<?php

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function activityApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function hotelForActivities(): Hotel
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

it('lets an employee list and view their own hotel activities for the booking form', function () {
    $hotel = hotelForActivities();
    $otherHotel = hotelForActivities();
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $own = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);
    $other = Activity::create(['hotel_id' => $otherHotel->id, 'name' => 'Sailing', 'price' => 20]);

    $list = $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson('/api/activity')
        ->assertOk();

    expect($list->json('body.data.*.id'))->toBe([$own->id]);

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/activity/{$own->id}")
        ->assertOk();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->getJson("/api/activity/{$other->id}")
        ->assertForbidden();
});

it('does not let an employee change activities', function () {
    $hotel = hotelForActivities();
    $employee = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id]);
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Sailing', 'price' => 20])
        ->assertForbidden();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['name' => 'Snorkelling'])
        ->assertForbidden();

    $this->withHeaders(activityApiHeaders())->actingAs($employee, 'sanctum')
        ->deleteJson("/api/activity/{$activity->id}")
        ->assertForbidden();
});

function activityAdmin(Hotel $hotel): User
{
    return User::query()->findOrFail($hotel->owner_id);
}

it('stores and returns an activity timeframe', function () {
    $hotel = hotelForActivities();

    $timeframe = [
        'available_from' => '2026-10-01',
        'available_until' => '2027-04-30',
        'operating_hours' => [
            'monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '18:00']],
            'saturday' => [['start' => '10:00', 'end' => '16:00']],
        ],
        'unavailable_periods' => [
            ['start_date' => '2026-12-24', 'end_date' => '2026-12-26', 'reason' => 'Holiday closure'],
        ],
    ];

    $response = $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10, ...$timeframe])
        ->assertCreated();

    expect($response->json('body'))->toMatchArray($timeframe);

    $activity = Activity::findOrFail($response->json('body.id'));
    expect($activity->available_from->toDateString())->toBe('2026-10-01')
        ->and($activity->operating_hours['monday'])->toHaveCount(2);
});

it('leaves the timeframe unrestricted when none is sent', function () {
    $hotel = hotelForActivities();

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10])
        ->assertCreated()
        ->assertJsonPath('body.available_from', null)
        ->assertJsonPath('body.available_until', null)
        ->assertJsonPath('body.operating_hours', null)
        ->assertJsonPath('body.unavailable_periods', null);
});

it('rejects a malformed timeframe', function (array $payload, string $errorKey) {
    $hotel = hotelForActivities();

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Diving', ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'unknown weekday' => [['operating_hours' => ['funday' => [['start' => '09:00', 'end' => '10:00']]]], 'operating_hours'],
    'bad time format' => [['operating_hours' => ['monday' => [['start' => '9am', 'end' => '10:00']]]], 'operating_hours.monday.0.start'],
    'end before start' => [['operating_hours' => ['monday' => [['start' => '18:00', 'end' => '09:00']]]], 'operating_hours.monday.0.end'],
    'missing end' => [['operating_hours' => ['monday' => [['start' => '09:00']]]], 'operating_hours.monday.0.end'],
    'overlapping slots' => [['operating_hours' => ['monday' => [['start' => '12:00', 'end' => '15:00'], ['start' => '09:00', 'end' => '12:30']]]], 'operating_hours.monday'],
    'season ends before it starts' => [['available_from' => '2026-10-01', 'available_until' => '2026-09-01'], 'available_until'],
    'period ends before it starts' => [['unavailable_periods' => [['start_date' => '2026-12-26', 'end_date' => '2026-12-24']]], 'unavailable_periods.0.end_date'],
    'period missing start' => [['unavailable_periods' => [['end_date' => '2026-12-24']]], 'unavailable_periods.0.start_date'],
    'periods not a list' => [['unavailable_periods' => ['xmas' => ['start_date' => '2026-12-24', 'end_date' => '2026-12-26']]], 'unavailable_periods'],
]);

it('accepts back-to-back slots and an empty day as closed', function () {
    $hotel = hotelForActivities();

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', [
            'hotel_id' => $hotel->id,
            'name' => 'Diving',
            'operating_hours' => [
                'monday' => [['start' => '09:00', 'end' => '12:00'], ['start' => '12:00', 'end' => '15:00']],
                'sunday' => [],
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('body.operating_hours.sunday', []);
});

it('checks the season order against the stored value on a partial update', function () {
    $hotel = hotelForActivities();
    $activity = Activity::create([
        'hotel_id' => $hotel->id,
        'name' => 'Diving',
        'available_from' => '2026-10-01',
    ]);

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['available_until' => '2026-09-01'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('available_until');

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['available_until' => '2027-04-30'])
        ->assertOk()
        ->assertJsonPath('body.available_from', '2026-10-01')
        ->assertJsonPath('body.available_until', '2027-04-30');
});

it('clears a timeframe column when it is set to null', function () {
    $hotel = hotelForActivities();
    $activity = Activity::create([
        'hotel_id' => $hotel->id,
        'name' => 'Diving',
        'operating_hours' => ['monday' => [['start' => '09:00', 'end' => '12:00']]],
    ]);

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['operating_hours' => null])
        ->assertOk()
        ->assertJsonPath('body.operating_hours', null);
});

it('accepts and returns audience, duration_days and daily_capacity', function () {
    $hotel = hotelForActivities();

    $response = $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', [
            'hotel_id' => $hotel->id,
            'name' => 'PADI Open Water',
            'price' => 450,
            'audience' => 'adults_only',
            'duration_days' => 3,
            'daily_capacity' => 6,
        ])
        ->assertCreated()
        ->assertJsonPath('body.audience', 'adults_only')
        ->assertJsonPath('body.duration_days', 3)
        ->assertJsonPath('body.daily_capacity', 6);

    $activity = Activity::findOrFail($response->json('body.id'));

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['audience' => 'family', 'daily_capacity' => null])
        ->assertOk()
        ->assertJsonPath('body.audience', 'family')
        ->assertJsonPath('body.duration_days', 3)
        ->assertJsonPath('body.daily_capacity', null);
});

it('rejects out-of-range pitching attributes', function (array $payload, string $errorKey) {
    $hotel = hotelForActivities();

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10, ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'a duration_days of zero' => [['duration_days' => 0], 'duration_days'],
    'a duration_days over 30' => [['duration_days' => 31], 'duration_days'],
    'an unknown audience value' => [['audience' => 'teens'], 'audience'],
    'a negative daily_capacity' => [['daily_capacity' => -5], 'daily_capacity'],
    // Zero would mean "full every day" and silently disable pitching it.
    'a daily_capacity of zero' => [['daily_capacity' => 0], 'daily_capacity'],
]);

it('rejects out-of-range pitching attributes on update', function () {
    $hotel = hotelForActivities();
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10]);

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->putJson("/api/activity/{$activity->id}", ['duration_days' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration_days');
});

it('leaves all three pitching attributes null when not supplied', function () {
    $hotel = hotelForActivities();

    $this->withHeaders(activityApiHeaders())->actingAs(activityAdmin($hotel), 'sanctum')
        ->postJson('/api/activity', ['hotel_id' => $hotel->id, 'name' => 'Diving', 'price' => 10])
        ->assertCreated()
        ->assertJsonPath('body.audience', null)
        ->assertJsonPath('body.duration_days', null)
        ->assertJsonPath('body.daily_capacity', null);
});
