<?php

use App\Ai\Agents\AdminAdvisorAgent;
use App\Ai\Agents\GuestConciergeAgent;
use App\Ai\Tools\GetAvailabilityTool;
use App\Ai\Tools\GetGuestAvailabilityTool;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Resources\AvailabilityResource;
use App\Models\Guest;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function atDays(int $days): string
{
    return now()->addDays($days)->toDateString();
}

it('gives the Admin AI exactly the numbers the endpoint gives staff', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 3);
    avRooms($hotel, $deluxe, 1, 'maintenance');
    avBook($hotel, $deluxe, atDays(5), atDays(7), units: 2);
    avType($hotel, 'Suite');

    $result = (string) (new GetAvailabilityTool($hotel, $hotel->owner))->handle(new Request([
        'arrival_date' => atDays(4),
        'departure_date' => atDays(8),
    ]));

    $expected = AvailabilityResource::make(app(AvailabilityService::class)->forHotel($hotel, atDays(4), atDays(8)))->resolve();
    $endpoint = $this->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($hotel->owner, 'sanctum')
        ->getJson('/api/availability?'.http_build_query(['arrival_date' => atDays(4), 'departure_date' => atDays(8)]))
        ->json('body');

    expect(json_decode($result, true))->toBe($expected)->toBe($endpoint);
});

it('narrows the Admin AI lookup to a room type named in any case', function () {
    $hotel = avHotel();
    avType($hotel, 'Deluxe');
    avType($hotel, 'Suite');

    $result = json_decode((string) (new GetAvailabilityTool($hotel, $hotel->owner))->handle(new Request([
        'arrival_date' => atDays(1), 'departure_date' => atDays(2), 'room_type' => 'suite',
    ])), true);

    expect(collect($result['room_types'])->pluck('room_type.name')->all())->toBe(['Suite']);
});

it('refuses the Admin AI lookup for someone without availability.view', function () {
    $hotel = avHotel();
    $role = StaffRole::create(['hotel_id' => $hotel->id, 'name' => 'No availability', 'permissions' => [Permission::RESERVATIONS_VIEW->value]]);
    $clerk = User::factory()->role(UserRole::EMPLOYEE)->create(['hotel_id' => $hotel->id, 'staff_role_id' => $role->id]);

    expect((string) (new GetAvailabilityTool($hotel, $clerk))->handle(new Request([
        'arrival_date' => atDays(1), 'departure_date' => atDays(2),
    ])))->toBe('You do not have permission to view room availability.');
});

it('tells the Admin AI plainly about an unknown type or a bad range', function (array $input, string $message) {
    $hotel = avHotel();
    avType($hotel, 'Deluxe');

    expect((string) (new GetAvailabilityTool($hotel, $hotel->owner))->handle(new Request($input)))->toBe($message);
})->with([
    'unknown type' => [fn () => ['arrival_date' => atDays(1), 'departure_date' => atDays(2), 'room_type' => 'Penthouse'], 'This hotel has no room type called "Penthouse".'],
    '32 nights' => [fn () => ['arrival_date' => atDays(1), 'departure_date' => atDays(33)], 'Availability can be checked for at most 31 nights at a time.'],
    'past' => [fn () => ['arrival_date' => atDays(-1), 'departure_date' => atDays(2)], 'The arrival date cannot be in the past.'],
    'not a date' => [fn () => ['arrival_date' => 'next friday', 'departure_date' => atDays(2)], 'The arrival_date must be a date in YYYY-MM-DD format.'],
]);

it('tells a guest only whether each type is available, the one they asked about first', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    avBook($hotel, $deluxe, atDays(3), atDays(5));
    avRooms($hotel, avType($hotel, 'Family'), 2);
    avRooms($hotel, avType($hotel, 'Suite'), 1);
    avType($hotel, 'Retired', active: false);

    $raw = (string) (new GetGuestAvailabilityTool($hotel))->handle(new Request([
        'arrival_date' => atDays(3), 'departure_date' => atDays(5), 'room_type' => 'deluxe',
    ]));
    $result = json_decode($raw, true);

    expect(collect($result['room_types'])->map(fn ($row) => [$row['name'], $row['available']])->all())
        ->toBe([['Deluxe', false], ['Family', true], ['Suite', true]])
        ->and(array_keys($result['room_types'][0]))->toBe(['name', 'description', 'max_occupancy', 'adult_capacity', 'child_capacity', 'available'])
        ->and($result)->not->toHaveKey('note');

    foreach (['total', 'booked', 'sellable', 'out_of_order', 'overbooked', 'room_number', 'bookable_for_stay'] as $key) {
        expect($raw)->not->toContain("\"{$key}\"");
    }
});

it('notes a room type the hotel does not have, and still lists the ones it does', function () {
    $hotel = avHotel();
    avRooms($hotel, avType($hotel, 'Deluxe'), 1);

    $result = json_decode((string) (new GetGuestAvailabilityTool($hotel))->handle(new Request([
        'arrival_date' => atDays(1), 'departure_date' => atDays(2), 'room_type' => 'Penthouse',
    ])), true);

    expect($result['note'])->toBe('The hotel has no room type called "Penthouse".')
        ->and(collect($result['room_types'])->pluck('name')->all())->toBe(['Deluxe']);
});

it('rejects more than 31 nights for the Concierge too', function () {
    $hotel = avHotel();

    expect((string) (new GetGuestAvailabilityTool($hotel))->handle(new Request([
        'arrival_date' => atDays(1), 'departure_date' => atDays(33),
    ])))->toBe('Availability can be checked for at most 31 nights at a time.');
});

it('gives both agents their availability tool', function () {
    $hotel = avHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-av', 'channel' => 'booking_com', 'first_name' => 'Mona']);

    $admin = collect(AdminAdvisorAgent::make(user: $hotel->owner)->tools())->map(fn ($tool) => $tool::class);
    $concierge = collect(GuestConciergeAgent::make(guest: $guest, hotel: $hotel, reservation: null)->tools())->map(fn ($tool) => $tool::class);

    expect($admin)->toContain(GetAvailabilityTool::class)->not->toContain(GetGuestAvailabilityTool::class)
        ->and($concierge)->toContain(GetGuestAvailabilityTool::class)->not->toContain(GetAvailabilityTool::class);
});
