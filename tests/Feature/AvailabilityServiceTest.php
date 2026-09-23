<?php

use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * The grid row of one type, keyed by night.
 *
 * @return array{bookable_for_stay: int, nights: array<string, array<string, mixed>>}
 */
function avRow(array $grid, RoomType $type): array
{
    $row = collect($grid['room_types'])->first(fn (array $row) => $row['room_type']->id === $type->id);

    expect($row)->not->toBeNull();

    return ['bookable_for_stay' => $row['bookable_for_stay'], 'nights' => collect($row['nights'])->keyBy('date')->all()];
}

function avService(): AvailabilityService
{
    return app(AvailabilityService::class);
}

it('reports every room of a type as sellable when nothing is booked', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 5);

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-15'), $deluxe);

    expect($row['bookable_for_stay'])->toBe(5)
        ->and(array_keys($row['nights']))->toBe(['2027-03-12', '2027-03-13', '2027-03-14'])
        ->and($row['nights']['2027-03-12'])->toBe([
            'date' => '2027-03-12', 'total' => 5, 'out_of_order' => 0, 'booked' => 0, 'sellable' => 5, 'overbooked' => 0,
        ]);
});

it('takes out-of-order rooms and booked lines off the sellable count, night by night', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 4);
    avRooms($hotel, $deluxe, 1, 'maintenance');
    avBook($hotel, $deluxe, '2027-03-13', '2027-03-14', units: 2);

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-15'), $deluxe);

    expect(array_column($row['nights'], 'sellable'))->toBe([4, 2, 4])
        ->and($row['nights']['2027-03-13']['booked'])->toBe(2)
        ->and($row['nights']['2027-03-13']['out_of_order'])->toBe(1)
        ->and($row['bookable_for_stay'])->toBe(2);
});

it('counts an out-of-order room on future nights too', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 2);
    avRooms($hotel, $deluxe, 1, 'maintenance');

    $row = avRow(avService()->forHotel($hotel, '2028-01-01', '2028-01-03'), $deluxe);

    expect(array_column($row['nights'], 'out_of_order'))->toBe([1, 1])
        ->and(array_column($row['nights'], 'sellable'))->toBe([2, 2]);
});

it('does not count the departure date, so back-to-back stays share a room', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    avBook($hotel, $deluxe, '2027-03-11', '2027-03-13');
    avBook($hotel, $deluxe, '2027-03-13', '2027-03-15');

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-14'), $deluxe);

    expect($row['nights']['2027-03-12']['booked'])->toBe(1)
        ->and($row['nights']['2027-03-13']['booked'])->toBe(1)
        ->and($row['nights']['2027-03-13']['overbooked'])->toBe(0);
});

it('holds rooms for pending, confirmed and checked-in reservations only', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 10);

    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'pending');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'confirmed');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'checked_in');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'checked_out');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'cancelled');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', 'confirmed', lineStatus: 'cancelled');
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13')->delete();

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-13'), $deluxe);

    expect($row['nights']['2027-03-12']['booked'])->toBe(3);
});

it('counts an unassigned line against its type exactly like an assigned one', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    [$room] = avRooms($hotel, $deluxe, 3);

    createReservationWithRooms($hotel, [['room_id' => $room->id], ['room_type_id' => $deluxe->id]], [
        'arrival_date' => '2027-03-12', 'departure_date' => '2027-03-13',
    ]);

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-13'), $deluxe);

    expect($row['nights']['2027-03-12']['booked'])->toBe(2)
        ->and($row['nights']['2027-03-12']['sellable'])->toBe(1);
});

it('keeps holding tonight for a checked-in guest past their departure date', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 2);
    $today = avService()->today($hotel);

    avBook($hotel, $deluxe, now()->subDays(3)->toDateString(), now()->subDay()->toDateString(), 'checked_in');
    avBook($hotel, $deluxe, now()->subDays(3)->toDateString(), now()->subDay()->toDateString(), 'confirmed');

    $row = avRow(avService()->forHotel($hotel, $today, now()->addDays(2)->toDateString()), $deluxe);

    expect($row['nights'][$today]['booked'])->toBe(1)
        ->and(collect($row['nights'])->except($today)->sum('booked'))->toBe(0);
});

it('frees tonight for a checked-in guest who is due out today, even before they check out', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    $today = avService()->today($hotel);

    avBook($hotel, $deluxe, now()->subDays(2)->toDateString(), $today, 'checked_in');

    $row = avRow(avService()->forHotel($hotel, $today, now()->addDay()->toDateString()), $deluxe);

    expect($row['nights'][$today]['booked'])->toBe(0)
        ->and($row['nights'][$today]['sellable'])->toBe(1);
});

it('reports how far a night is overbooked and never a negative sellable count', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 1);
    avBook($hotel, $deluxe, '2027-03-12', '2027-03-13', units: 3);

    $cell = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-13'), $deluxe)['nights']['2027-03-12'];

    expect($cell['sellable'])->toBe(0)->and($cell['overbooked'])->toBe(2);
});

it('lists active types by name by default, and named types even when inactive', function () {
    $hotel = avHotel();
    $suite = avType($hotel, 'Suite');
    $deluxe = avType($hotel, 'Deluxe');
    $retired = avType($hotel, 'Retired', active: false);
    $deleted = avType($hotel, 'Deleted');
    $deleted->delete();

    $default = avService()->forHotel($hotel, '2027-03-12', '2027-03-13');
    $named = avService()->forHotel($hotel, '2027-03-12', '2027-03-13', [$retired->id, $deleted->id]);

    expect(collect($default['room_types'])->map(fn ($row) => $row['room_type']->name)->all())->toBe(['Deluxe', 'Suite'])
        ->and(collect($named['room_types'])->map(fn ($row) => $row['room_type']->name)->all())->toBe(['Retired']);
});

it('returns zeros for a type with no rooms', function () {
    $hotel = avHotel();
    $empty = avType($hotel, 'Empty');

    $row = avRow(avService()->forHotel($hotel, '2027-03-12', '2027-03-14'), $empty);

    expect($row['bookable_for_stay'])->toBe(0)
        ->and(array_column($row['nights'], 'total'))->toBe([0, 0]);
});

it('never lets the migration placeholder type reduce a real type', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 2);
    $placeholder = avType($hotel, 'Unspecified (migrated)', active: false);
    avBook($hotel, $placeholder, '2027-03-12', '2027-03-13', units: 4);

    $grid = avService()->forHotel($hotel, '2027-03-12', '2027-03-13');

    expect(avRow($grid, $deluxe)['nights']['2027-03-12']['sellable'])->toBe(2)
        ->and(collect($grid['room_types'])->pluck('room_type.name')->all())->toBe(['Deluxe']);
});

it('ignores another hotel\'s rooms and reservations', function () {
    $hotel = avHotel();
    $other = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 2);
    $otherDeluxe = avType($other, 'Deluxe');
    avRooms($other, $otherDeluxe, 7);
    avBook($other, $otherDeluxe, '2027-03-12', '2027-03-13', units: 3);

    $grid = avService()->forHotel($hotel, '2027-03-12', '2027-03-13');

    expect($grid['room_types'])->toHaveCount(1)
        ->and(avRow($grid, $deluxe)['nights']['2027-03-12'])->toMatchArray(['total' => 2, 'booked' => 0])
        ->and(avService()->forHotel($hotel, '2027-03-12', '2027-03-13', [$otherDeluxe->id])['room_types'])->toBe([]);
});

it('runs the same three queries for one night and for ninety', function () {
    $hotel = avHotel();
    $deluxe = avType($hotel, 'Deluxe');
    avRooms($hotel, $deluxe, 3);
    avBook($hotel, $deluxe, '2027-03-12', '2027-04-20', units: 2);

    DB::enableQueryLog();
    avService()->forHotel($hotel, '2027-03-12', '2027-03-13');
    $oneNight = count(DB::getQueryLog());

    DB::flushQueryLog();
    avService()->forHotel($hotel, '2027-03-12', '2027-06-10');
    $ninety = count(DB::getQueryLog());

    expect($oneNight)->toBe(3)->and($ninety)->toBe(3);
});

it('rejects an empty, too long or past range', function (callable $range, string $key) {
    $hotel = avHotel('Asia/Dubai');
    [$arrival, $departure] = $range(avService()->today($hotel));

    expect(fn () => avService()->assertRange($hotel, $arrival, $departure))
        ->toThrow(ValidationException::class);

    try {
        avService()->assertRange($hotel, $arrival, $departure);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($key);
    }
})->with([
    'departure before arrival' => [fn (string $today) => [$today, $today], 'departure_date'],
    'ninety-one nights' => [fn (string $today) => [$today, date('Y-m-d', strtotime("{$today} +91 days"))], 'departure_date'],
    'yesterday in hotel time' => [fn (string $today) => [date('Y-m-d', strtotime("{$today} -1 day")), $today], 'arrival_date'],
]);

it('accepts ninety nights starting today in the hotel\'s time zone', function () {
    $hotel = avHotel('Pacific/Kiritimati');
    $today = avService()->today($hotel);

    avService()->assertRange($hotel, $today, date('Y-m-d', strtotime("{$today} +90 days")));

    expect(true)->toBeTrue();
});
