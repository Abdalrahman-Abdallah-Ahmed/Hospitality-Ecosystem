<?php

use App\Ai\Tools\GetActivitiesTool;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

// Monday 5 October 2026.
beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    $this->travelTo('2026-10-05 09:00:00');
});

/**
 * Fridays 17:00-19:00 for 12 people through October, closed Friday the 16th.
 */
function abCalendarActivity($hotel)
{
    return abActivity($hotel, [
        'operating_hours' => ['friday' => [['start' => '17:00', 'end' => '19:00']]],
        'available_until' => '2026-10-31',
        'unavailable_periods' => [['start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'reason' => 'Hull repair']],
        'daily_capacity' => 12,
    ]);
}

function abAvailability($test, User $user, $activity, string $query)
{
    return fdGet($test, $user, "/api/activity/{$activity->id}/availability?{$query}");
}

it('reports each date of a range with its state and places', function () {
    $hotel = avHotel();
    $activity = abCalendarActivity($hotel);
    abBook($hotel, $activity, '2026-10-09 17:00', 5);
    abBook($hotel, $activity, '2026-10-23 17:00', 12);

    $days = collect(abAvailability($this, User::find($hotel->owner_id), $activity, 'from=2026-10-08&to=2026-11-06')
        ->assertOk()
        ->assertJsonPath('body.daily_capacity', 12)
        ->json('body.days'))->keyBy('date');

    expect($days)->toHaveCount(30)
        ->and($days['2026-10-09'])->toMatchArray([
            'open' => true, 'reason' => null, 'capacity' => 12, 'booked' => 5, 'remaining' => 7,
            'windows' => [['start' => '17:00', 'end' => '19:00']], 'past' => false,
        ])
        ->and($days['2026-10-08'])->toMatchArray(['open' => false, 'reason' => 'closed_weekday', 'windows' => []])
        ->and($days['2026-10-16'])->toMatchArray(['open' => false, 'reason' => 'closure_period', 'closure_reason' => 'Hull repair'])
        ->and($days['2026-10-23'])->toMatchArray(['open' => true, 'reason' => 'fully_booked', 'remaining' => 0])
        ->and($days['2026-11-06'])->toMatchArray(['open' => false, 'reason' => 'out_of_season']);
});

it('answers a single date when no end is given', function () {
    $hotel = avHotel();
    $activity = abCalendarActivity($hotel);

    abAvailability($this, User::find($hotel->owner_id), $activity, 'from=2026-10-09')
        ->assertOk()
        ->assertJsonCount(1, 'body.days')
        ->assertJsonPath('body.days.0.date', '2026-10-09');
});

it('refuses a range longer than 31 days or one that ends first', function () {
    $hotel = avHotel();
    $admin = User::find($hotel->owner_id);
    $activity = abCalendarActivity($hotel);

    abAvailability($this, $admin, $activity, 'from=2026-10-01&to=2026-10-31')->assertOk();
    abAvailability($this, $admin, $activity, 'from=2026-10-01&to=2026-11-01')->assertStatus(422)->assertJsonValidationErrors('to');
    abAvailability($this, $admin, $activity, 'from=2026-10-09&to=2026-10-08')->assertStatus(422);
    abAvailability($this, $admin, $activity, 'from=09-10-2026')->assertStatus(422);
});

it('flags past days and closed days that still hold bookings', function () {
    $hotel = avHotel();
    $activity = abCalendarActivity($hotel);
    abBook($hotel, $activity, '2026-10-09 17:00', 3);
    $activity->update(['unavailable_periods' => [['start_date' => '2026-10-09', 'end_date' => '2026-10-09', 'reason' => 'Storm']]]);

    $days = collect(abAvailability($this, User::find($hotel->owner_id), $activity, 'from=2026-10-02&to=2026-10-09')
        ->assertOk()->json('body.days'))->keyBy('date');

    expect($days['2026-10-02']['past'])->toBeTrue()
        ->and($days['2026-10-09'])->toMatchArray(['open' => false, 'booked' => 3, 'has_bookings_while_closed' => true]);
});

it('hides another hotel activity', function () {
    $hotel = avHotel();
    $theirs = abCalendarActivity(avHotel());

    abAvailability($this, User::find($hotel->owner_id), $theirs, 'from=2026-10-09')->assertForbidden();
});

it('needs the activities view permission', function () {
    $hotel = avHotel();
    $activity = abCalendarActivity($hotel);

    abAvailability($this, fdEmployee($hotel, []), $activity, 'from=2026-10-09')->assertForbidden();
    abAvailability($this, fdEmployee($hotel, [Permission::ACTIVITIES_VIEW]), $activity, 'from=2026-10-09')->assertOk();
});

it('gives the concierge the same availability as the calendar', function () {
    $hotel = avHotel();
    $activity = abCalendarActivity($hotel);
    abBook($hotel, $activity, '2026-10-09 17:00', 5);

    $tool = json_decode((string) (new GetActivitiesTool($hotel))->handle(new Request(['from' => '2026-10-08', 'to' => '2026-10-16'])), true);
    $calendar = abAvailability($this, User::find($hotel->owner_id), $activity, 'from=2026-10-08&to=2026-10-16')->json('body.days');

    $toolDays = collect($tool[0]['availability'])->keyBy('date');

    foreach ($calendar as $day) {
        expect($toolDays[$day['date']]['remaining'])->toBe(max(0, $day['remaining']))
            ->and($toolDays[$day['date']]['open'])->toBe($day['open'] && $day['remaining'] > 0);
    }

    // No date asked: the listing is as it always was.
    $plain = json_decode((string) (new GetActivitiesTool($hotel))->handle(new Request([])), true);

    expect($plain[0])->not->toHaveKey('availability')
        ->and((string) (new GetActivitiesTool($hotel))->handle(new Request(['from' => '2026-10-01', 'to' => '2026-10-30'])))
        ->toContain('at most 14 days');
});
