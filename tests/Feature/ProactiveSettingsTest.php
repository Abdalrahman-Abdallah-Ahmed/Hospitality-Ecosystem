<?php

use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

it('shows every proactive setting at its default, off', function () {
    $hotel = rapHotel();

    rapRequest($this, rapAdmin($hotel), 'GET', "/api/hotel/{$hotel->id}")
        ->assertOk()
        ->assertJsonPath('body.proactive_settings.enabled', false)
        ->assertJsonPath('body.proactive_settings.quiet_hours', ['start' => '21:00', 'end' => '09:00'])
        ->assertJsonPath('body.proactive_settings.daily_cap', 1)
        ->assertJsonPath('body.proactive_settings.triggers.first_morning', true);
});

it('merges a partial update over the stored settings', function () {
    $hotel = rapHotel();
    $admin = rapAdmin($hotel);

    rapRequest($this, $admin, 'PUT', "/api/hotel/{$hotel->id}", ['proactive_settings' => ['enabled' => true]])->assertOk();
    rapRequest($this, $admin, 'PUT', "/api/hotel/{$hotel->id}", ['proactive_settings' => ['quiet_hours' => ['start' => '22:30'], 'triggers' => ['mid_stay' => false]]])
        ->assertOk()
        ->assertJsonPath('body.proactive_settings.enabled', true)
        ->assertJsonPath('body.proactive_settings.quiet_hours', ['start' => '22:30', 'end' => '09:00'])
        ->assertJsonPath('body.proactive_settings.triggers.mid_stay', false)
        ->assertJsonPath('body.proactive_settings.triggers.first_morning', true);
});

it('refuses invalid or unknown settings', function (array $settings) {
    $hotel = rapHotel();

    rapRequest($this, rapAdmin($hotel), 'PUT', "/api/hotel/{$hotel->id}", ['proactive_settings' => $settings])->assertStatus(422);

    expect($hotel->fresh()->proactive_settings)->toBeNull();
})->with([
    'bad time' => [['quiet_hours' => ['start' => '25:00']]],
    'cap too low' => [['daily_cap' => 0]],
    'cap too high' => [['daily_cap' => 4]],
    'hours before too high' => [['reminder' => ['hours_before' => 25]]],
    'unknown key' => [['send_at_midnight' => true]],
    'unknown trigger' => [['triggers' => ['birthday' => true]]],
]);

it('leaves proactive settings to admins', function () {
    $hotel = rapHotel();
    $employee = rapEmployee($hotel, Permission::cases());

    rapRequest($this, $employee, 'PUT', "/api/hotel/{$hotel->id}", ['proactive_settings' => ['enabled' => true]])->assertForbidden();

    expect($hotel->fresh()->proactiveSettings()->enabled)->toBeFalse();
});
