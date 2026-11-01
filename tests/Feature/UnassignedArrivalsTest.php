<?php

use App\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| GET /api/reservation/unassigned-arrivals (spec 020 US3, FR-018).
*/

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);

    [$this->hotel, $this->type, $this->rooms] = fdHotel(6);
    $this->admin = $this->hotel->owner;
    $this->at = fn (int $fromToday, int $nights = 2) => [
        'arrival_date' => now()->addDays($fromToday)->toDateString(),
        'departure_date' => now()->addDays($fromToday + $nights)->toDateString(),
    ];
});

function uaLineIds($response): array
{
    return collect($response->json('body.data'))->pluck('line_id')->all();
}

it('lists only the lines still waiting for a room in the window', function () {
    fdBook($this->hotel, $this->type, [$this->rooms[0]->id], ($this->at)(1));
    $partly = fdBook($this->hotel, $this->type, [$this->rooms[1]->id, null], ($this->at)(2));
    $none = fdBook($this->hotel, $this->type, [null], [...($this->at)(3), 'reservation_id' => 'RES-NONE']);
    fdBook($this->hotel, $this->type, [null], ($this->at)(9));

    $response = fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals')->assertOk();

    $partlyLine = $partly->reservationRooms()->whereNull('room_id')->sole();
    $noneLine = $none->reservationRooms()->sole();

    expect(uaLineIds($response))->toEqualCanonicalizing([$partlyLine->id, $noneLine->id]);
    $response->assertJsonPath('body.data.1.reservation_code', 'RES-NONE')
        ->assertJsonPath('body.data.1.room_type.name', 'Deluxe')
        ->assertJsonPath('body.data.1.reservation_status', 'confirmed')
        ->assertJsonPath('body.data.1.is_late', false)
        ->assertJsonPath('body.data.1.arrival_date', now()->addDays(3)->toDateString());
    expect($response->json('body.data.1.primary_guest.name'))->not->toBeEmpty();
});

it('needs both ends of a window, so the cap always applies', function (string $query) {
    fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals?'.$query)->assertUnprocessable();
})->with([
    'only to' => ['to=2035-01-01'],
    'only from' => ['from=2026-11-01'],
]);

it('takes a window and refuses one longer than 31 days', function () {
    $later = fdBook($this->hotel, $this->type, [null], ($this->at)(9));
    $from = now()->addDays(8)->toDateString();

    $response = fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals?from='.$from.'&to='.now()->addDays(10)->toDateString())->assertOk();
    expect(uaLineIds($response))->toBe([$later->reservationRooms()->sole()->id]);

    fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals?from='.$from.'&to='.now()->addDays(40)->toDateString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

it('marks a late arrival that can still come', function () {
    $late = fdBook($this->hotel, $this->type, [null], ($this->at)(-1, 3));

    fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals')
        ->assertOk()
        ->assertJsonPath('body.data.0.line_id', $late->reservationRooms()->sole()->id)
        ->assertJsonPath('body.data.0.is_late', true);
});

it('lists a room still on its way on a reservation whose first room is in', function () {
    $reservation = fdBook($this->hotel, $this->type, [$this->rooms[0]->id, null]);
    [$first] = fdStays($reservation);
    fdPost($this, $this->admin, "/api/stays/{$first->id}/check-in")->assertOk();

    expect($reservation->fresh()->status->value)->toBe('checked_in');
    expect(uaLineIds(fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals')))
        ->toBe([$reservation->reservationRooms()->whereNull('room_id')->sole()->id]);
});

it('leaves out no-shows, cancelled lines and other hotels', function () {
    $noShow = fdBook($this->hotel, $this->type, [null]);
    [$stay] = fdStays($noShow);
    fdPost($this, $this->admin, "/api/stays/{$stay->id}/no-show")->assertOk();

    $cancelled = fdBook($this->hotel, $this->type, [null, null], ($this->at)(1));
    $cancelled->reservationRooms()->first()->update(['status' => 'cancelled']);

    [$other, $otherType] = fdHotel(1);
    fdBook($other, $otherType, [null], ($this->at)(1));

    expect(uaLineIds(fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals')))
        ->toBe([$cancelled->reservationRooms()->where('status', 'reserved')->sole()->id]);
});

it('pages and sorts', function () {
    $first = fdBook($this->hotel, $this->type, [null], ($this->at)(1));
    $second = fdBook($this->hotel, $this->type, [null], ($this->at)(2));

    $response = fdGet($this, $this->admin, '/api/reservation/unassigned-arrivals?sort=-arrival_date&per_page=1')->assertOk();

    expect(uaLineIds($response))->toBe([$second->reservationRooms()->sole()->id])
        ->and($response->json('body.meta.total'))->toBe(2);
    expect($first)->not->toBeNull();
});

it('needs reservations.assign_room', function () {
    fdGet($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_VIEW]), '/api/reservation/unassigned-arrivals')->assertForbidden();
    fdGet($this, fdEmployee($this->hotel, [Permission::RESERVATIONS_ASSIGN_ROOM]), '/api/reservation/unassigned-arrivals')->assertOk();
});
