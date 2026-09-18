<?php

use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\CreateGuestServiceRequestTool;
use App\Enums\Priority;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function vipConciergeHotel(): Hotel
{
    $owner = User::factory()->create();

    return Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Grand Harbor Hotel',
        'slug' => 'grand-harbor-'.$owner->id,
        'currency' => 'USD',
    ]);
}

function towelRequest(string $priority): Request
{
    return new Request([
        'title' => 'Extra towels',
        'description' => 'Two extra bath towels, please.',
        'priority' => $priority,
    ]);
}

it('raises a VIP guest service request to high priority whatever priority the model chose', function () {
    $hotel = vipConciergeHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'is_vip' => true]);

    (new CreateGuestServiceRequestTool($guest, $hotel))->handle(towelRequest('low'));

    expect(Task::sole()->priority)->toBe(Priority::HIGH);
});

it('keeps the chosen priority for a guest who is not VIP', function () {
    $hotel = vipConciergeHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara']);

    (new CreateGuestServiceRequestTool($guest, $hotel))->handle(towelRequest('low'));

    expect(Task::sole()->priority)->toBe(Priority::LOW);
});

it('tells the concierge to give a VIP guest extra care without revealing the status', function () {
    $hotel = vipConciergeHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'is_vip' => true]);

    $instructions = (string) (new GuestConciergeAgent($guest, $hotel))->instructions();

    expect($instructions)
        ->toContain('one of the hotel\'s VIP guests')
        ->toContain('Never mention VIP status');
});

it('adds no VIP guidance to the concierge prompt for a regular guest', function () {
    $hotel = vipConciergeHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara']);

    $instructions = (string) (new GuestConciergeAgent($guest, $hotel))->instructions();

    expect($instructions)->not->toContain('VIP');
});
