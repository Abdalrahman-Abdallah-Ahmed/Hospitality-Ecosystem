<?php

use App\Enums\ImportRowOutcome;
use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
| SPEC-100/101 User Story 4: a guest is reused only on a confident match by
| phone, email or old-system id; details pointing at two people make the
| reservation unresolved, never merged (FR-018–FR-020, SC-005).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
    Storage::fake('local');
});

const GM_HEADER = ['reservation_reference', 'guest_first_name', 'guest_last_name', 'guest_phone', 'guest_email', 'guest_external_id', 'arrival_date', 'departure_date', 'adults'];

function gmRow(string $ref, array $guest): array
{
    return [$ref, $guest['first'] ?? '', $guest['last'] ?? '', $guest['phone'] ?? '', $guest['email'] ?? '', $guest['ext'] ?? '', '2026-11-01', '2026-11-03', $guest['adults'] ?? ''];
}

function gmPrimary(string $ref): ?string
{
    return Reservation::withoutGlobalScope('hotel')->where('reservation_id', $ref)->value('guest_id');
}

it('reuses an existing guest matched by phone, email or old-system id', function () {
    [$admin, $hotel] = imHotel();
    $sara = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'phone_number' => '+201001234567', 'email' => 'Sara@Example.com']);
    $omar = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Omar', 'phone_number' => '201009999999', 'external_id' => 'G-77', 'external_source' => 'import']);

    $import = imImport($this, $admin, imCsv(GM_HEADER, [
        gmRow('M-1', ['phone' => '0020 100 123 4567']),
        gmRow('M-2', ['email' => 'sara@example.com']),
        gmRow('M-3', ['ext' => 'g-77']),
    ]));

    expect($import->imported)->toBe(3)
        ->and(gmPrimary('M-1'))->toBe($sara->id)
        ->and(gmPrimary('M-2'))->toBe($sara->id)
        ->and(gmPrimary('M-3'))->toBe($omar->id)
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(2);
});

it('leaves a reservation unresolved when phone and email point at different guests, writing nothing', function () {
    [$admin, $hotel] = imHotel();
    Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Ahmed', 'last_name' => 'Saleh', 'phone_number' => '201001234567']);
    Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Mona', 'last_name' => 'Saleh', 'email' => 'mona@example.com']);

    $import = imImport($this, $admin, imCsv(GM_HEADER, [gmRow('U-1', ['phone' => '01001234567', 'email' => 'mona@example.com'])]));
    $row = imRows($import)[2];

    expect($row->outcome)->toBe(ImportRowOutcome::UNRESOLVED)
        ->and($row->reason)->toContain('Ahmed Saleh')->toContain('Mona Saleh')
        ->and($row->reason)->not->toContain('201001234567')->not->toContain('mona@example.com')
        ->and(Reservation::withoutGlobalScope('hotel')->exists())->toBeFalse()
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(2);
});

it('leaves a reservation unresolved when its guest details point at two people named earlier in the file', function () {
    [$admin, $hotel] = imHotel();

    $import = imImport($this, $admin, imCsv(GM_HEADER, [
        gmRow('F-1', ['phone' => '01001110001', 'email' => 'one@example.com']),
        gmRow('F-2', ['phone' => '01001110002', 'email' => 'two@example.com']),
        gmRow('F-3', ['phone' => '01001110002', 'email' => 'one@example.com']),
        gmRow('F-4', ['email' => 'one@example.com']),
    ]));

    expect(imRows($import)[4]->outcome)->toBe(ImportRowOutcome::UNRESOLVED)
        ->and(imRows($import)[4]->reason)->toContain('rows 2 and 3')
        ->and(gmPrimary('F-4'))->toBe(gmPrimary('F-1'))
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(2);
});

it('never overwrites a matched guest, but fills in what is empty', function () {
    [$admin, $hotel] = imHotel();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Sara', 'last_name' => 'Ali', 'phone_number' => '201001234567']);

    imImport($this, $admin, imCsv(GM_HEADER, [gmRow('O-1', ['first' => 'Sarah', 'last' => 'Aly', 'phone' => '01001234567', 'email' => 'sara@example.com', 'ext' => 'OLD-9'])]));

    expect($guest->fresh()->only(['first_name', 'last_name', 'email', 'external_id', 'external_source']))->toBe([
        'first_name' => 'Sara',
        'last_name' => 'Ali',
        'email' => 'sara@example.com',
        'external_id' => 'OLD-9',
        'external_source' => 'import',
    ]);
});

it('never matches a guest of another hotel', function () {
    [$admin, $hotel] = imHotel();
    [, $other] = imHotel();
    $foreign = Guest::create(['hotel_id' => $other->id, 'phone_number' => '201001234567', 'email' => 'sara@example.com']);

    imImport($this, $admin, imCsv(GM_HEADER, [gmRow('X-1', ['phone' => '01001234567', 'email' => 'sara@example.com'])]));

    expect(gmPrimary('X-1'))->not->toBe($foreign->id)
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and($foreign->fresh()->updated_at->eq($foreign->updated_at))->toBeTrue();
});

it('links the same person on two reservations to one new guest', function () {
    [$admin, $hotel] = imHotel();

    imImport($this, $admin, imCsv(GM_HEADER, [
        gmRow('S-1', ['first' => 'Laila', 'phone' => '01001110009']),
        gmRow('S-2', ['email' => 'laila@example.com', 'phone' => '+20 100 111 0009']),
    ]));

    expect(gmPrimary('S-1'))->toBe(gmPrimary('S-2'))
        ->and(Guest::where('hotel_id', $hotel->id)->sole()->email)->toBe('laila@example.com');
});

it('matches an existing guest on everything the file says about the person, across rows', function () {
    [$admin, $hotel] = imHotel();
    $existing = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Huda', 'email' => 'huda@example.com']);

    $import = imImport($this, $admin, imCsv(GM_HEADER, [
        gmRow('A-1', ['phone' => '01005550001']),
        gmRow('A-2', ['phone' => '01005550001', 'email' => 'huda@example.com']),
    ]));

    expect($import->imported)->toBe(2)
        ->and(gmPrimary('A-1'))->toBe($existing->id)
        ->and(gmPrimary('A-2'))->toBe($existing->id)
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(1)
        ->and($existing->fresh()->phone_number)->toBe('201005550001');
});

it('matches a guest whose phone was stored without the country code', function () {
    [$admin, $hotel] = imHotel();
    $existing = Guest::create(['hotel_id' => $hotel->id, 'first_name' => 'Karim', 'phone_number' => '01001234567']);

    imImport($this, $admin, imCsv(GM_HEADER, [gmRow('N-1', ['phone' => '01001234567'])]));

    expect(gmPrimary('N-1'))->toBe($existing->id)
        ->and(Guest::where('hotel_id', $hotel->id)->count())->toBe(1);
});
