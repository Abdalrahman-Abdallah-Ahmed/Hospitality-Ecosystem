<?php

use App\Enums\Permission;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\ProactiveMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function logRow(Hotel $hotel, Guest $guest, array $attributes = []): ProactiveMessage
{
    return tap((new ProactiveMessage)->forceFill([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'trigger' => 'first_morning',
        'event_key' => 'first_morning:'.uniqid(),
        'status' => 'skipped',
        'reason' => 'outside_window',
        'due_at' => now(),
        'valid_until' => now()->addHours(3),
        ...$attributes,
    ]))->save();
}

it('lists what was sent and why the rest was not, for admins', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);
    $skipped = logRow($hotel, $guest, ['due_at' => now()->subHour()]);
    $sent = logRow($hotel, $guest, ['trigger' => 'upcoming_activity', 'status' => 'sent', 'reason' => null, 'sent_at' => now(), 'body' => 'A reminder']);

    $all = rapRequest($this, rapAdmin($hotel), 'GET', '/api/proactive-messages')->assertOk();
    $onlySkipped = rapRequest($this, rapAdmin($hotel), 'GET', '/api/proactive-messages?filter[status]=skipped')->assertOk();
    $sentToday = rapRequest($this, rapAdmin($hotel), 'GET', '/api/proactive-messages?sent_from='.now($hotel->timezone)->toDateString())->assertOk();

    expect(collect($all->json('body.data'))->pluck('id')->all())->toBe([$sent->id, $skipped->id])
        ->and($all->json('body.data.1.reason'))->toBe('outside_window')
        ->and(collect($onlySkipped->json('body.data'))->pluck('id')->all())->toBe([$skipped->id])
        ->and(collect($sentToday->json('body.data'))->pluck('id')->all())->toBe([$sent->id]);

    rapRequest($this, rapAdmin($hotel), 'GET', "/api/proactive-messages/{$sent->id}")
        ->assertOk()
        ->assertJsonPath('body.body', 'A reminder')
        ->assertJsonPath('body.guest.id', $guest->id);
});

it('keeps the log from employees, whatever their role grants', function () {
    $hotel = rapHotel();

    rapRequest($this, rapEmployee($hotel, Permission::cases()), 'GET', '/api/proactive-messages')->assertForbidden();
});

it('keeps one hotel out of another hotel\'s log', function () {
    $hotelA = rapHotel();
    $hotelB = rapHotel();
    [$guestB] = rapInHouseStay($hotelB);
    $rowB = logRow($hotelB, $guestB);

    expect(rapRequest($this, rapAdmin($hotelA), 'GET', '/api/proactive-messages')->json('body.data'))->toBe([]);
    rapRequest($this, rapAdmin($hotelA), 'GET', "/api/proactive-messages/{$rowB->id}")->assertForbidden();
});
