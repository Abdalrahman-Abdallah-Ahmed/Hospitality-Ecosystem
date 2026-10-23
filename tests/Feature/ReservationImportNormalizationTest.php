<?php

use App\Enums\ImportDateOrder;
use App\Enums\ImportDecimalMark;
use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Support\Import\DateParser;
use App\Support\Import\PhoneNormalizer;
use App\Support\Import\Reservations\ImportFailed;
use App\Support\Import\Reservations\ImportFileReader;
use App\Support\Import\Reservations\RowNormalizer;
use App\Support\Import\Reservations\RowValidator;

/*
| SPEC-100: the normalize stage reads what legacy exports write, and never
| guesses (FR-007, FR-008, FR-008a).
*/

function nzCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'nz').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function nzRow(array $values, ?string $countryCode = 'EG', ImportDateOrder $order = ImportDateOrder::DAY_FIRST, ImportDecimalMark $decimalMark = ImportDecimalMark::AUTO)
{
    $hotel = new Hotel(['country_code' => $countryCode, 'timezone' => 'UTC']);

    return (new RowNormalizer)->normalize(2, $values, $order, $hotel, $decimalMark);
}

// --- Dates ---------------------------------------------------------------

it('reads a date in the formats legacy exports use', function (mixed $raw, ImportDateOrder $order, ?string $expected) {
    expect(DateParser::parse($raw, $order)?->toDateString())->toBe($expected);
})->with([
    'day first' => ['12/09/2025', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'month first' => ['12/09/2025', ImportDateOrder::MONTH_FIRST, '2025-12-09'],
    'iso ignores the order' => ['2025-09-12', ImportDateOrder::MONTH_FIRST, '2025-09-12'],
    'dashes and a short year' => ['12-09-25', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'dots' => ['12.09.2025', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'day month-name year' => ['12-Sep-2025', ImportDateOrder::MONTH_FIRST, '2025-09-12'],
    'full month name' => ['12 September 2025', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'month-name day, year' => ['Sep 12, 2025', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'with a time' => ['2025-09-12 14:30', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'excel serial' => [45912, ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'excel serial as text' => ['45912', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'arabic-indic digits' => ['١٢/٠٩/٢٠٢٥', ImportDateOrder::DAY_FIRST, '2025-09-12'],
    'impossible date' => ['31/02/2025', ImportDateOrder::DAY_FIRST, null],
    'words' => ['next week', ImportDateOrder::DAY_FIRST, null],
    'unknown month' => ['12 Foo 2025', ImportDateOrder::DAY_FIRST, null],
]);

// --- Phones --------------------------------------------------------------

it('puts phone numbers in the stored form using the hotel country', function (string $raw, ?string $country, ?string $digits, ?string $warning) {
    expect(PhoneNormalizer::normalize($raw, $country))->toBe(['digits' => $digits, 'warning' => $warning]);
})->with([
    'international with spaces' => ['+20 100 123 4567', 'EG', '201001234567', null],
    'double-zero prefix' => ['0020 100 123 4567', 'EG', '201001234567', null],
    'double-zero, no hotel country' => ['0020 100 123 4567', null, '201001234567', null],
    'national at an Egyptian hotel' => ['01001234567', 'EG', '201001234567', null],
    'italian landline keeps its trunk 0' => ['06 1234 5678', 'IT', '390612345678', null],
    'arabic-indic digits' => ['٠١٠٠١٢٣٤٥٦٧', 'EG', '201001234567', null],
    'national with no hotel country' => ['01001234567', null, '01001234567', 'phone_country_unknown'],
    'invalid for the hotel country' => ['0100123', 'EG', '0100123', 'phone_unrecognized'],
]);

it('returns nothing for an empty phone', function () {
    expect(PhoneNormalizer::normalize('  ', 'EG'))->toBe(['digits' => null, 'warning' => null]);
});

// --- Headers -------------------------------------------------------------

it('maps headers through the synonym table and lists the rest once', function () {
    $path = nzCsv("Guest Phone,Res No.,Check-In,Departure,Fax,Fax\n01001234567,R-1,2025-09-12,2025-09-14,x,y");

    $read = (new ImportFileReader)->read($path);

    expect($read['rows'][0]['values'])->toMatchArray([
        'guest_phone' => '01001234567',
        'reservation_reference' => 'R-1',
        'arrival_date' => '2025-09-12',
        'departure_date' => '2025-09-14',
    ])
        ->and($read['rows'][0]['row_number'])->toBe(2)
        ->and($read['unrecognized'])->toBe(['Fax']);
});

it('reads the abbreviated headers of PMS reservation reports', function () {
    $path = nzCsv("Conf. No.,Profile No.,Arr. Date,Dep. Date,Rms\nGHH-1,P010001,2025-09-12,2025-09-14,2");

    expect((new ImportFileReader)->read($path)['rows'][0]['values'])->toMatchArray([
        'reservation_reference' => 'GHH-1',
        'guest_external_id' => 'P010001',
        'arrival_date' => '2025-09-12',
        'departure_date' => '2025-09-14',
        'room_count' => '2',
    ]);
});

it('skips title rows above a chosen header row and keeps the file\'s row numbers (016)', function () {
    $path = nzCsv("Grand Harbor Hotel,,\nReservations report,,\nguest_phone,arrival_date,departure_date\n01001234567,2025-09-12,2025-09-14");

    $rows = (new ImportFileReader)->read($path, headerRow: 3)['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['row_number'])->toBe(4)
        ->and($rows[0]['values']['guest_phone'])->toBe('01001234567');
});

it('reads only mapped columns when a column map is given, listing the rest (016)', function () {
    $path = nzCsv("Arr,Dep,Mob,Fax,Email\n2025-09-12,2025-09-14,01001234567,x,a@example.test");

    $read = (new ImportFileReader)->read($path, columnMap: [0 => 'arrival_date', 1 => 'departure_date', 2 => 'guest_phone', 3 => null]);

    expect($read['rows'][0]['values'])->toBe([
        'arrival_date' => '2025-09-12',
        'departure_date' => '2025-09-14',
        'guest_phone' => '01001234567',
    ])
        ->and($read['unrecognized'])->toBe(['Fax', 'Email']);
});

it('ignores a known header the column map leaves unmapped (016)', function () {
    $path = nzCsv("arrival_date,departure_date,guest_phone,guest_email\n2025-09-12,2025-09-14,01001234567,a@example.test");

    $read = (new ImportFileReader)->read($path, columnMap: ['0' => 'arrival_date', '1' => 'departure_date', '2' => 'guest_phone', '3' => null]);

    expect($read['rows'][0]['values'])->not->toHaveKey('guest_email')
        ->and($read['unrecognized'])->toBe(['guest_email']);
});

it('fails a file missing a required column, naming it', function () {
    $path = nzCsv("guest_phone,arrival_date\n01001234567,2025-09-12");

    expect(fn () => (new ImportFileReader)->read($path))
        ->toThrow(ImportFailed::class, 'departure_date');
});

it('fails a file with no guest identifier column', function () {
    $path = nzCsv("guest_first_name,arrival_date,departure_date\nSara,2025-09-12,2025-09-14");

    expect(fn () => (new ImportFileReader)->read($path))
        ->toThrow(ImportFailed::class, 'guest_phone or guest_email or guest_external_id');
});

it('drops blank rows after the last data row but keeps blank rows between data rows', function () {
    $path = nzCsv("guest_phone,arrival_date,departure_date\n01001234567,2025-09-12,2025-09-14\n,,\n01001234568,2025-09-12,2025-09-14\n,,\n,,\n,,");

    $rows = (new ImportFileReader)->read($path)['rows'];

    expect(array_column($rows, 'row_number'))->toBe([2, 3, 4]);
});

// --- Statuses and values -------------------------------------------------

it('maps legacy status labels', function (string $label, ReservationStatus $status) {
    expect(nzRow(['status' => $label])->status)->toBe($status);
})->with([
    ['Checked Out', ReservationStatus::CHECKED_OUT],
    ['CO', ReservationStatus::CHECKED_OUT],
    ['No Show', ReservationStatus::NO_SHOW],
    ['no-show', ReservationStatus::NO_SHOW],
    ['In House', ReservationStatus::CHECKED_IN],
    ['cxl', ReservationStatus::CANCELLED],
    ['Guaranteed', ReservationStatus::CONFIRMED],
    ['tentative', ReservationStatus::PENDING],
    ['checked_out', ReservationStatus::CHECKED_OUT],
    ['C/O', ReservationStatus::CHECKED_OUT],
    ['Due Out', ReservationStatus::CHECKED_IN],
    ['Waitlist', ReservationStatus::PENDING],
]);

it('treats an unknown status label as an error, not a guess', function () {
    $row = nzRow(['status' => 'Lost']);

    expect($row->status)->toBeNull()
        ->and($row->errors)->toHaveKey('status');
});

it('defaults an empty status to pending', function () {
    expect(nzRow(['status' => '', 'guest_phone' => '01001234567'])->status)->toBe(ReservationStatus::PENDING);
});

it('rejects a non-numeric party and an age over 17', function () {
    $row = nzRow(['adults' => 'two', 'children_ages' => '5,18']);

    expect($row->errors)->toHaveKeys(['adults', 'children_ages']);
});

it('reads amounts with thousands separators or a decimal comma', function (string $raw, float $value) {
    expect(nzRow(['reservation_value' => $raw])->reservationValue)->toBe($value);
})->with([
    ['1,200.50', 1200.5],
    ['1.200,50', 1200.5],
    ['1.234.567', 1234567.0],
    ['99,50', 99.5],
    ['99.5', 99.5],
    ['EGP 3500', 3500.0],
]);

it('normalizes a guest from the guest columns, lower-casing the email', function () {
    $row = nzRow(['guest_phone' => '01001234567', 'guest_email' => ' Sara@Example.COM ', 'is_primary' => 'yes']);

    expect($row->guests[0])->toMatchArray([
        'phone' => '201001234567',
        'email' => 'sara@example.com',
        'is_primary' => true,
    ]);
});

it('reads amounts by the decimal mark chosen for the file (016)', function (mixed $raw, ImportDecimalMark $mark, ?float $expected) {
    $row = nzRow(['reservation_value' => $raw, 'guest_phone' => '01001234567'], decimalMark: $mark);

    if ($expected === null) {
        expect($row->reservationValue)->toBeNull()
            ->and($row->errors['reservation_value'] ?? '')->toContain((string) $raw);
    } else {
        expect($row->reservationValue)->toBe($expected)
            ->and($row->errors)->not->toHaveKey('reservation_value');
    }
})->with([
    'auto: thousands alone is ambiguous' => ['25,200', ImportDecimalMark::AUTO, null],
    'auto: both marks' => ['1,260.50', ImportDecimalMark::AUTO, 1260.5],
    'auto: currency and thousands' => ['EGP 3,600', ImportDecimalMark::AUTO, null],
    'auto: comma decimal' => ['99,50', ImportDecimalMark::AUTO, 99.5],
    'dot: thousands' => ['25,200', ImportDecimalMark::DOT, 25200.0],
    'dot: both marks' => ['1,260.50', ImportDecimalMark::DOT, 1260.5],
    'dot: currency and thousands' => ['EGP 3,600', ImportDecimalMark::DOT, 3600.0],
    'dot: millions' => ['1,250,000', ImportDecimalMark::DOT, 1250000.0],
    'dot: plain decimal' => ['99.5', ImportDecimalMark::DOT, 99.5],
    'dot: comma decimal does not fit' => ['99,50', ImportDecimalMark::DOT, null],
    'dot: comma-decimal thousands do not fit' => ['1.260,50', ImportDecimalMark::DOT, null],
    'comma: thousands' => ['25.200', ImportDecimalMark::COMMA, 25200.0],
    'comma: both marks' => ['1.260,50', ImportDecimalMark::COMMA, 1260.5],
    'comma: decimal' => ['99,50', ImportDecimalMark::COMMA, 99.5],
    'comma: dot-decimal thousands do not fit' => ['1,260.50', ImportDecimalMark::COMMA, null],
    'a numeric cell is never reinterpreted' => [1260.5, ImportDecimalMark::COMMA, 1260.5],
]);

it('splits a full-name column into first and last name (016)', function (string $raw, ?string $first, ?string $last) {
    $row = nzRow(['guest_full_name' => $raw, 'guest_phone' => '01001234567']);

    expect($row->guests[0])->toMatchArray(['first_name' => $first, 'last_name' => $last]);
})->with([
    'family name of several words' => ['Mohamed Abdel Rahman', 'Mohamed', 'Abdel Rahman'],
    'last name before a comma' => ['Smith, John', 'John', 'Smith'],
    'one word' => ['  Sara  ', 'Sara', null],
    'empty' => ['', null, null],
    'repeated spaces' => ['Ahmed   El  Sayed', 'Ahmed', 'El Sayed'],
]);

it('prefers separate first and last name columns over a full name (016)', function () {
    $row = nzRow(['guest_full_name' => 'Ahmed Saleh', 'guest_first_name' => 'Mona', 'guest_phone' => '01001234567']);

    expect($row->guests[0])->toMatchArray(['first_name' => 'Mona', 'last_name' => null]);
});

it('still needs a contact for a guest named only by full name (016)', function () {
    $row = nzRow(['guest_full_name' => 'Ahmed Saleh', 'arrival_date' => '2025-09-12', 'departure_date' => '2025-09-14']);
    (new RowValidator)->validate($row);

    expect($row->errors['guest_phone'] ?? null)->toBe('Ahmed Saleh has no phone, email or guest id. Add at least one.');
});

it('reads a Guest Name header as the full-name column (016)', function () {
    $path = nzCsv("Guest Name,guest_phone,arrival_date,departure_date\nAhmed Saleh,01001234567,2025-09-12,2025-09-14");

    expect((new ImportFileReader)->read($path)['rows'][0]['values'])->toHaveKey('guest_full_name', 'Ahmed Saleh');
});

it('refuses an amount whose separator could mean thousands or decimals', function (string $raw) {
    $row = nzRow(['reservation_value' => $raw, 'guest_phone' => '01001234567']);

    expect($row->reservationValue)->toBeNull()
        ->and($row->errors['reservation_value'])->toContain('more than one way');
})->with(['1,200', '1.200', '12,500']);

it('finds the national form of a number for matching guests stored without a country code', function () {
    expect(PhoneNormalizer::nationalDigits('201001234567', 'EG'))->toBe('01001234567')
        ->and(PhoneNormalizer::nationalDigits('201001234567', 'IT'))->toBeNull()
        ->and(PhoneNormalizer::nationalDigits('201001234567', null))->toBeNull();
});
