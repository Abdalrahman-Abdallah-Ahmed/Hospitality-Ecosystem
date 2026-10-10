<?php

use App\Enums\Permission;
use App\Models\EventLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('lets staff record a guest\'s contact preference, once per change', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);
    $admin = rapAdmin($hotel);

    rapRequest($this, $admin, 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => true])
        ->assertOk()
        ->assertJsonPath('body.proactive_opted_out', true)
        ->assertJsonPath('body.proactive_opt_out_source', 'staff');
    rapRequest($this, $admin, 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => true])->assertOk();
    rapRequest($this, $admin, 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => false])
        ->assertOk()
        ->assertJsonPath('body.proactive_opted_out', false);

    expect(EventLog::where('subject_id', $guest->id)->where('event_type', 'guest.opted_out')->count())->toBe(1)
        ->and(EventLog::where('subject_id', $guest->id)->where('event_type', 'guest.opted_in')->count())->toBe(1);
});

it('requires a boolean', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => 'maybe'])->assertStatus(422);
});

it('needs the guests.update permission', function () {
    $hotel = rapHotel();
    [$guest] = rapInHouseStay($hotel);

    rapRequest($this, rapEmployee($hotel, [Permission::GUESTS_VIEW]), 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => true])->assertForbidden();
    rapRequest($this, rapEmployee($hotel, [Permission::GUESTS_UPDATE]), 'PUT', "/api/guest/{$guest->id}/contact-preference", ['proactive_opted_out' => true])->assertOk();
});

it('keeps another hotel out of a guest\'s contact preference', function () {
    $hotelA = rapHotel();
    $hotelB = rapHotel();
    [$guestB] = rapInHouseStay($hotelB);

    rapRequest($this, rapAdmin($hotelA), 'PUT', "/api/guest/{$guestB->id}/contact-preference", ['proactive_opted_out' => true])->assertForbidden();

    expect($guestB->fresh()->proactive_opted_out_at)->toBeNull();
});
