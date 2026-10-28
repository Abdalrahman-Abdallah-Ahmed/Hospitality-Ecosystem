<?php

use App\Enums\Permission;
use App\Http\Resources\ReservationResource;
use App\Models\EventLog;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\RoomType;
use App\Support\Reservations\ReservationCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/** @return array{0: Hotel, 1: RoomType, 2: Guest} */
function partyFixture(): array
{
    $hotel = avHotel();
    $type = avType($hotel, 'Family');
    avRooms($hotel, $type, 3);
    $guest = gsGuest($hotel, ['phone_number' => '+201000000001']);

    return [$hotel, $type, $guest];
}

/** @param list<array<string, mixed>>|null $guests */
function partyReservation($hotel, RoomType $type, Guest $primary, array $attributes = [], ?array $guests = null, ?array $rooms = null): Reservation
{
    return ReservationCreator::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'reservation_id' => 'PARTY-'.Str::random(8),
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'status' => 'confirmed',
        'adults' => 2,
        'children' => 0,
        'children_ages' => [],
        'reservation_value' => 100,
        ...$attributes,
        ...($guests !== null ? ['guests' => $guests] : []),
    ], $rooms ?? [['room_type_id' => $type->id]]);
}

it('keeps one primary member and updates the compatibility guest pointer', function () {
    [$hotel, $type, $primary] = partyFixture();
    $additional = gsGuest($hotel, ['phone_number' => '+201000000002']);

    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $additional->id, 'is_primary' => false],
    ]);

    expect($reservation->reservationGuests()->count())->toBe(2)
        ->and($reservation->reservationGuests()->where('is_primary', true)->sole()->guest_id)->toBe($primary->id)
        ->and($reservation->guests()->count())->toBe(2);
});

it('omits a soft-deleted guest from the guest list without changing the primary pointer', function () {
    [$hotel, $type, $primary] = partyFixture();
    $additional = gsGuest($hotel, ['phone_number' => '+201000000013']);
    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $additional->id, 'is_primary' => false],
    ]);

    $additional->delete();
    $resource = ReservationResource::make($reservation->fresh()->load(['guest', 'reservationGuests.guest']))->resolve();

    expect($resource['guest_id'])->toBe($primary->id)
        ->and($resource['guest_count'])->toBe(1)
        ->and($resource['guests'])->toHaveCount(1)
        ->and($reservation->fresh()->guests()->count())->toBe(1);
});

it('rejects duplicate members and a party larger than the booked total', function () {
    [$hotel, $type, $primary] = partyFixture();

    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $primary->id, 'is_primary' => false],
    ]))->toThrow(ValidationException::class);

    $second = gsGuest($hotel, ['phone_number' => '+201000000003']);
    $third = gsGuest($hotel, ['phone_number' => '+201000000004']);
    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $second->id, 'is_primary' => false],
        ['guest_id' => $third->id, 'is_primary' => false],
    ]))->toThrow(ValidationException::class);
});

it('validates every supplied child age', function () {
    [$hotel, $type, $primary] = partyFixture();

    partyReservation($hotel, $type, $primary, ['adults' => 1, 'children' => 2, 'children_ages' => [0, 17]]);

    expect(fn () => partyReservation($hotel, $type, $primary, ['children' => 1, 'children_ages' => [18]]))
        ->toThrow(ValidationException::class);
});

it('reuses a guest found by phone or an unambiguous same-hotel email', function () {
    [$hotel, $type, $primary] = partyFixture();
    $byPhone = gsGuest($hotel, ['phone_number' => '+20 100 000 0002']);
    $byEmail = gsGuest($hotel, ['email' => 'shared@example.test']);
    gsGuest($hotel, ['email' => 'shared@example.test'])->delete();

    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['phone_number' => '201000000002', 'is_primary' => false],
    ]);

    expect($reservation->reservationGuests()->where('guest_id', $byPhone->id)->exists())->toBeTrue()
        ->and($reservation->reservationGuests()->count())->toBe(2);

    $emailReservation = partyReservation($hotel, $type, $primary, ['reservation_id' => 'EMAIL-'.Str::random(8)], [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['email' => 'shared@example.test', 'is_primary' => false],
    ]);

    expect($emailReservation->reservationGuests()->where('guest_id', $byEmail->id)->exists())->toBeTrue();
});

it('rejects ambiguous emails, conflicting contacts, and a mismatched primary pointer', function () {
    [$hotel, $type, $primary] = partyFixture();
    $duplicateEmail = 'shared-'.Str::random(8).'@example.test';
    gsGuest($hotel, ['email' => $duplicateEmail]);
    gsGuest($hotel, ['email' => $duplicateEmail]);

    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['email' => $duplicateEmail, 'is_primary' => false],
    ]))->toThrow(ValidationException::class);

    $other = gsGuest($hotel, ['phone_number' => '+201000000005']);
    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $other->id, 'is_primary' => true],
    ]))->toThrow(ValidationException::class);

    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $other->id, 'phone_number' => '+201000000006', 'is_primary' => true],
    ]))->toThrow(ValidationException::class);
});

it('rejects an additional guest from another hotel without creating a reservation', function () {
    [$hotel, $type, $primary] = partyFixture();
    $otherHotel = avHotel();
    $foreignGuest = gsGuest($otherHotel, ['phone_number' => '+201000000012']);

    expect(fn () => partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $foreignGuest->id, 'is_primary' => false],
    ]))->toThrow(ValidationException::class);

    expect(Reservation::where('reservation_id', 'like', 'PARTY-%')->count())->toBe(0);
});

it('updates the primary guest, compatibility pointer, and current stays together', function () {
    [$hotel, $type, $primary] = partyFixture();
    $additional = gsGuest($hotel, ['phone_number' => '+201000000007']);
    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $additional->id, 'is_primary' => false],
    ], rooms: [['room_type_id' => $type->id, 'quantity' => 2]]);
    $departedStay = $reservation->stays()->first();
    $departedStay->update(['status' => 'departed']);
    $currentStay = $reservation->stays()->where('id', '!=', $departedStay->id)->first();

    ReservationCreator::update($reservation, ['guests' => [
        ['guest_id' => $primary->id, 'is_primary' => false],
        ['guest_id' => $additional->id, 'is_primary' => true],
    ]], null);

    expect($reservation->fresh()->guest_id)->toBe($additional->id)
        ->and($reservation->reservationGuests()->where('is_primary', true)->sole()->guest_id)->toBe($additional->id)
        ->and($currentStay?->fresh()->guest_id)->toBe($additional->id)
        ->and($departedStay->fresh()->guest_id)->toBe($primary->id)
        ->and(EventLog::withoutGlobalScope('hotel')->where('subject_id', $reservation->id)->where('event_type', 'reservation.updated')->exists())->toBeTrue()
        ->and(EventLog::withoutGlobalScope('hotel')->where('subject_id', $currentStay?->id)->where('event_type', 'stay.updated')->exists())->toBeTrue();
});

it('returns the complete marked guest list and child ages from the reservation API', function () {
    [$admin, $hotel] = adminWithHotel();
    $primary = gsGuest($hotel, ['phone_number' => '+201000000008']);
    $additional = gsGuest($hotel, ['phone_number' => '+201000000009']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $primary->id,
            'reservation_id' => 'PARTY-API-'.Str::random(6),
            'arrival_date' => now()->addDays(7)->toDateString(),
            'departure_date' => now()->addDays(9)->toDateString(),
            'adults' => 2,
            'children' => 2,
            'children_ages' => [5, 8],
            'guests' => [
                ['guest_id' => $primary->id, 'is_primary' => true],
                ['guest_id' => $additional->id, 'is_primary' => false],
            ],
            'rooms' => [['room_type_id' => bookableTypeIdFor($hotel), 'quantity' => 2]],
        ]);

    $response->assertCreated()
        ->assertJsonPath('body.guest_count', 2)
        ->assertJsonPath('body.guests.0.id', $primary->id)
        ->assertJsonPath('body.guests.0.is_primary', true)
        ->assertJsonPath('body.guests.1.id', $additional->id)
        ->assertJsonPath('body.guests.1.is_primary', false)
        ->assertJsonPath('body.children_ages', [5, 8]);
});

it('keeps legacy single-guest reservation creation compatible and marks that guest primary', function () {
    [$admin, $hotel] = adminWithHotel();
    $guest = gsGuest($hotel, ['phone_number' => '+201000000014']);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', [
            'hotel_id' => $hotel->id,
            'guest_id' => $guest->id,
            'reservation_id' => 'PARTY-SINGLE-'.Str::random(6),
            'arrival_date' => now()->addDays(7)->toDateString(),
            'departure_date' => now()->addDays(9)->toDateString(),
            'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
        ]);

    $response->assertCreated()
        ->assertJsonPath('body.guest_count', 1)
        ->assertJsonPath('body.guests.0.id', $guest->id)
        ->assertJsonPath('body.guests.0.is_primary', true);
});

it('updates a reservation guest list through the staff API', function () {
    [$admin, $hotel] = adminWithHotel();
    $type = avType($hotel, 'Party');
    avRooms($hotel, $type, 3);
    $primary = gsGuest($hotel, ['phone_number' => '+201000000015']);
    $additional = gsGuest($hotel, ['phone_number' => '+201000000016']);
    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
    ]);

    $response = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson('/api/reservation/'.$reservation->id, [
            'guests' => [
                ['guest_id' => $primary->id, 'is_primary' => false],
                ['guest_id' => $additional->id, 'is_primary' => true],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('body.guest_id', $additional->id)
        ->assertJsonPath('body.guests.0.is_primary', true)
        ->assertJsonPath('body.guests.0.id', $additional->id);
});

it('rejects reservation guest lists without exactly one primary or matching the legacy pointer', function () {
    [$admin, $hotel] = adminWithHotel();
    $primary = gsGuest($hotel, ['phone_number' => '+201000000010']);
    $other = gsGuest($hotel, ['phone_number' => '+201000000011']);
    $payload = [
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'adults' => 2,
        'children' => 0,
        'reservation_id' => 'PARTY-BAD-'.Str::random(6),
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
    ];

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        ...$payload,
        'guests' => [
            ['guest_id' => $primary->id, 'is_primary' => false],
            ['guest_id' => $other->id, 'is_primary' => false],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('guests');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        ...$payload,
        'reservation_id' => 'PARTY-TWO-PRIMARY-'.Str::random(6),
        'guests' => [
            ['guest_id' => $primary->id, 'is_primary' => true],
            ['guest_id' => $other->id, 'is_primary' => true],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('guests');

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        ...$payload,
        'reservation_id' => 'PARTY-MISMATCH-'.Str::random(6),
        'guests' => [
            ['guest_id' => $other->id, 'is_primary' => true],
            ['guest_id' => $primary->id, 'is_primary' => false],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('guests');

    $ageResponse = $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        ...$payload,
        'reservation_id' => 'PARTY-AGES-'.Str::random(6),
        'children' => 2,
        'children_ages' => [5],
        'guests' => [['guest_id' => $primary->id, 'is_primary' => true]],
    ]);
    $ageResponse->assertUnprocessable()->assertJsonValidationErrors('children_ages');

    expect(Reservation::where('hotel_id', $hotel->id)->count())->toBe(0);
});

it('rejects an additional guest from another hotel at the reservation API boundary', function () {
    [$admin, $hotel] = adminWithHotel();
    [, $otherHotel] = adminWithHotel();
    $primary = gsGuest($hotel, ['phone_number' => '+201000000017']);
    $foreign = gsGuest($otherHotel, ['phone_number' => '+201000000018']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')->postJson('/api/reservation', [
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'reservation_id' => 'PARTY-FOREIGN-'.Str::random(6),
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'adults' => 2,
        'guests' => [
            ['guest_id' => $primary->id, 'is_primary' => true],
            ['guest_id' => $foreign->id, 'is_primary' => false],
        ],
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
    ])->assertUnprocessable()->assertJsonValidationErrors('guests.1.guest_id');

    expect(Reservation::where('hotel_id', $hotel->id)->exists())->toBeFalse();
});

it('allows unrelated changes to a legacy reservation without child ages', function () {
    [$hotel, $type, $primary] = partyFixture();
    $reservation = Reservation::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'reservation_id' => 'LEGACY-'.Str::random(8),
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'status' => 'confirmed',
        'adults' => 1,
        'children' => 1,
        'children_ages' => [],
        'reservation_value' => 100,
    ]);

    ReservationCreator::update($reservation, ['special_requests' => 'Late arrival'], null);

    expect($reservation->fresh()->special_requests)->toBe('Late arrival');
});

it('lets an employee with reservations.create name a new guest without guests.create', function () {
    [, $hotel] = adminWithHotel();
    $employee = fdEmployee($hotel, [Permission::RESERVATIONS_CREATE]);
    $primary = gsGuest($hotel, ['phone_number' => '+201000000019']);

    $this->withHeaders(apiHeaders())->actingAs($employee, 'sanctum')->postJson('/api/reservation', [
        'hotel_id' => $hotel->id,
        'guest_id' => $primary->id,
        'reservation_id' => 'PARTY-EMP-'.Str::random(6),
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'adults' => 2,
        'guests' => [
            ['guest_id' => $primary->id, 'is_primary' => true],
            ['phone_number' => '+201000000020', 'first_name' => 'Maya', 'is_primary' => false],
        ],
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
    ])->assertCreated()->assertJsonPath('body.guest_count', 2);

    expect(Guest::where('hotel_id', $hotel->id)->where('first_name', 'Maya')->exists())->toBeTrue();
});

it('rejects ages for a reservation without children and requires ages when the children count changes', function () {
    [$hotel, $type, $primary] = partyFixture();

    expect(fn () => partyReservation($hotel, $type, $primary, ['children' => 0, 'children_ages' => [4]]))
        ->toThrow(ValidationException::class);

    $reservation = partyReservation($hotel, $type, $primary, ['adults' => 1, 'children' => 2, 'children_ages' => [4, 9]],
        rooms: [['room_type_id' => $type->id, 'quantity' => 2]]);

    expect(fn () => ReservationCreator::update($reservation, ['children' => 3], null))
        ->toThrow(ValidationException::class);

    ReservationCreator::update($reservation, ['children' => 3, 'children_ages' => [4, 9, 1]], null);

    expect($reservation->fresh()->children_ages)->toBe([4, 9, 1]);
});

it('keeps the party-capacity check on adult and child totals across all room lines', function () {
    [$hotel, $type, $primary] = partyFixture();
    $type->update(['max_occupancy' => 3, 'adult_capacity' => 2, 'child_capacity' => 1]);

    expect(fn () => partyReservation($hotel, $type, $primary, ['adults' => 2, 'children' => 2, 'children_ages' => [3, 6]]))
        ->toThrow(ValidationException::class);

    $twoRooms = partyReservation($hotel, $type, $primary, ['adults' => 2, 'children' => 2, 'children_ages' => [3, 6]],
        rooms: [['room_type_id' => $type->id, 'quantity' => 2]]);

    expect($twoRooms->children)->toBe(2);
});

it('adds and removes guests, refuses to drop the primary, and audits every change', function () {
    [$hotel, $type, $primary] = partyFixture();
    $friend = gsGuest($hotel, ['phone_number' => '+201000000021']);
    $reservation = partyReservation($hotel, $type, $primary, ['adults' => 2]);

    ReservationCreator::update($reservation, ['guests' => [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $friend->id, 'is_primary' => false],
    ]], null);
    expect($reservation->fresh()->guests()->count())->toBe(2);

    expect(fn () => ReservationCreator::update($reservation->fresh(), ['guests' => [
        ['guest_id' => $friend->id, 'is_primary' => false],
    ]], null))->toThrow(ValidationException::class);

    ReservationCreator::update($reservation->fresh(), ['guests' => [
        ['guest_id' => $primary->id, 'is_primary' => true],
    ]], null);

    $link = ReservationGuest::withTrashed()->where('reservation_id', $reservation->id)->where('guest_id', $friend->id)->sole();

    expect($reservation->fresh()->guests()->pluck('guests.id')->all())->toBe([$primary->id])
        ->and($friend->fresh())->not->toBeNull()
        ->and($link->trashed())->toBeTrue()
        ->and(EventLog::withoutGlobalScope('hotel')->where('subject_id', $link->id)->where('event_type', 'reservation_guest.created')->exists())->toBeTrue()
        ->and(EventLog::withoutGlobalScope('hotel')->where('subject_id', $link->id)->where('event_type', 'reservation_guest.deleted')->exists())->toBeTrue();
});

it('lets staff correct the guest list of a cancelled reservation', function () {
    [$hotel, $type, $primary] = partyFixture();
    $friend = gsGuest($hotel, ['phone_number' => '+201000000022']);
    $reservation = partyReservation($hotel, $type, $primary);
    ReservationCreator::update($reservation, ['status' => 'cancelled'], null);

    ReservationCreator::update($reservation->fresh(), ['guests' => [
        ['guest_id' => $primary->id, 'is_primary' => false],
        ['guest_id' => $friend->id, 'is_primary' => true],
    ]], null);

    expect($reservation->fresh()->guest_id)->toBe($friend->id);
});

it('rejects shrinking the party below the named guest list', function () {
    [$hotel, $type, $primary] = partyFixture();
    $friend = gsGuest($hotel, ['phone_number' => '+201000000023']);
    $reservation = partyReservation($hotel, $type, $primary, guests: [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $friend->id, 'is_primary' => false],
    ]);

    expect(fn () => ReservationCreator::update($reservation, ['adults' => 1], null))
        ->toThrow(ValidationException::class);

    expect($reservation->fresh()->adults)->toBe(2);
});

it('applies consecutive complete guest lists one after the other, keeping one primary', function () {
    [$hotel, $type, $primary] = partyFixture();
    $friend = gsGuest($hotel, ['phone_number' => '+201000000024']);
    $reservation = partyReservation($hotel, $type, $primary);

    ReservationCreator::update($reservation, ['guests' => [
        ['guest_id' => $primary->id, 'is_primary' => true],
        ['guest_id' => $friend->id, 'is_primary' => false],
    ]], null);
    ReservationCreator::update($reservation->fresh(), ['guests' => [
        ['guest_id' => $friend->id, 'is_primary' => true],
    ]], null);

    $links = ReservationGuest::where('reservation_id', $reservation->id)->get();

    expect($links)->toHaveCount(1)
        ->and($links->sole()->guest_id)->toBe($friend->id)
        ->and($links->sole()->is_primary)->toBeTrue()
        ->and($reservation->fresh()->guest_id)->toBe($friend->id);
});

it('accepts a new-contact primary on update when guest_id is left out', function () {
    [$admin, $hotel] = adminWithHotel();
    $type = avType($hotel, 'Swap');
    avRooms($hotel, $type, 2);
    $primary = gsGuest($hotel, ['phone_number' => '+201000000025']);
    $reservation = partyReservation($hotel, $type, $primary);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson('/api/reservation/'.$reservation->id, ['guests' => [
            ['guest_id' => $primary->id, 'is_primary' => false],
            ['phone_number' => '+201000000026', 'first_name' => 'Nour', 'is_primary' => true],
        ]])
        ->assertOk()
        ->assertJsonPath('body.guests.0.first_name', 'Nour')
        ->assertJsonPath('body.guests.0.is_primary', true);

    $newPrimary = Guest::where('hotel_id', $hotel->id)->where('first_name', 'Nour')->sole();
    expect($reservation->fresh()->guest_id)->toBe($newPrimary->id);
});

/** @param array<string, mixed> $attributes */
function newPrimaryPayload(Hotel $hotel, array $attributes = []): array
{
    return [
        'hotel_id' => $hotel->id,
        'reservation_id' => 'PARTY-NEW-'.Str::random(6),
        'arrival_date' => now()->addDays(7)->toDateString(),
        'departure_date' => now()->addDays(9)->toDateString(),
        'adults' => 2,
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel)]],
        ...$attributes,
    ];
}

it('creates a reservation for a new primary named by contact details when guest_id is left out', function () {
    [$admin, $hotel] = adminWithHotel();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', newPrimaryPayload($hotel, ['guests' => [
            ['first_name' => 'Omar', 'last_name' => 'Fathy', 'phone_number' => '+201000000031', 'email' => 'omar@example.com', 'is_primary' => true],
        ]]))
        ->assertCreated()
        ->assertJsonPath('body.guest_count', 1)
        ->assertJsonPath('body.guests.0.first_name', 'Omar')
        ->assertJsonPath('body.guests.0.is_primary', true);

    $guest = Guest::where('hotel_id', $hotel->id)->where('first_name', 'Omar')->sole();
    expect(Reservation::where('hotel_id', $hotel->id)->sole()->guest_id)->toBe($guest->id);
});

it('reuses an existing guest for a contact-named primary on create', function (array $contact) {
    [$admin, $hotel] = adminWithHotel();
    $existing = gsGuest($hotel, ['phone_number' => '+201000000032', 'email' => 'known@example.com']);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', newPrimaryPayload($hotel, ['guests' => [$contact]]))
        ->assertCreated()
        ->assertJsonPath('body.guest_id', $existing->id);

    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
})->with([
    'by phone' => [['phone_number' => '+20 100 000 0032']],
    'by email' => [['email' => 'KNOWN@example.com']],
]);

it('leaves no new guest behind when a create with a contact-named primary fails', function (Closure $payload, string $errorKey) {
    [$admin, $hotel] = adminWithHotel();
    $payload = $payload($hotel);
    $before = Guest::withoutGlobalScopes()->count();

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(Guest::withoutGlobalScopes()->count())->toBe($before)
        ->and(Reservation::withoutGlobalScopes()->where('hotel_id', $hotel->id)->exists())->toBeFalse();
})->with([
    'another hotel guest on the list' => [fn (Hotel $hotel) => newPrimaryPayload($hotel, ['guests' => [
        ['phone_number' => '+201000000033', 'is_primary' => true],
        ['guest_id' => gsGuest(avHotel())->id, 'is_primary' => false],
    ]]), 'guests.1.guest_id'],
    'party over capacity' => [fn (Hotel $hotel) => newPrimaryPayload($hotel, [
        'adults' => 9,
        'guests' => [['phone_number' => '+201000000034', 'is_primary' => true]],
    ]), 'rooms'],
    'overbooked' => [fn (Hotel $hotel) => newPrimaryPayload($hotel, [
        'rooms' => [['room_type_id' => bookableTypeIdFor($hotel), 'quantity' => 6]],
        'guests' => [['phone_number' => '+201000000035', 'is_primary' => true]],
    ]), 'rooms'],
]);

it('keeps the create guest rules when guest_id is left out', function (array $attributes, string $errorKey) {
    [$admin, $hotel] = adminWithHotel();
    $other = gsGuest($hotel, ['phone_number' => '+201000000036']);
    $attributes = json_decode(str_replace('OTHER', $other->id, json_encode($attributes)), true);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/reservation', newPrimaryPayload($hotel, $attributes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
})->with([
    'neither guest_id nor guests' => [[], 'guest_id'],
    'no primary' => [['guests' => [
        ['phone_number' => '+201000000037', 'is_primary' => false],
        ['guest_id' => 'OTHER', 'is_primary' => false],
    ]], 'guests'],
    'two primaries' => [['guests' => [
        ['phone_number' => '+201000000037', 'is_primary' => true],
        ['guest_id' => 'OTHER', 'is_primary' => true],
    ]], 'guests'],
    'guest_id not the primary' => [['guest_id' => 'OTHER', 'guests' => [
        ['phone_number' => '+201000000037', 'is_primary' => true],
    ]], 'guests'],
    'no contact details' => [['guests' => [['first_name' => 'Nobody', 'is_primary' => true]]], 'guests.0.guest_id'],
]);

it('accepts a contact-named entry listed before entries with a guest_id', function () {
    [$admin, $hotel] = adminWithHotel();
    $type = avType($hotel, 'Order');
    avRooms($hotel, $type, 2);
    $primary = gsGuest($hotel, ['phone_number' => '+201000000038']);
    $reservation = partyReservation($hotel, $type, $primary);

    $this->withHeaders(apiHeaders())->actingAs($admin, 'sanctum')
        ->putJson('/api/reservation/'.$reservation->id, ['guests' => [
            ['phone_number' => '+201000000039', 'first_name' => 'Sara', 'is_primary' => true],
            ['guest_id' => $primary->id, 'is_primary' => false],
        ]])
        ->assertOk()
        ->assertJsonPath('body.guest_count', 2);
});
