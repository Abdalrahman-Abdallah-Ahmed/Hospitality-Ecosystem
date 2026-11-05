<?php

use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Support\Export\SqlDump;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| SQL exports (spec 021 FR-012, FR-013): one INSERT per record with the
| stored values, ids kept, safely quoted, loadable into an empty table.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

/**
 * @return array{0: User, 1: Hotel}
 */
function sqlAdmin(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Sql Dump Hotel',
        'slug' => 'sql-dump-hotel-'.$admin->id,
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function sqlDumpOf(string $modelClass, Hotel $hotel, array $excluded = []): string
{
    $query = $modelClass::query()->where('hotel_id', $hotel->id)->orderBy('created_at');

    ob_start();
    (new SqlDump($query, 'test', $hotel, $query->count(), ['search' => 'x'], $excluded))->write();

    return ob_get_clean();
}

function sqlExport($test, User $admin, string $module): string
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($admin, 'sanctum')
        ->get("/api/{$module}/export?format=sql")
        ->assertOk()
        ->streamedContent();
}

it('quotes text, writes NULL, booleans and numbers as literals, and wraps it in a transaction', function () {
    [, $hotel] = sqlAdmin();
    Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => "O'Brien",
        'last_name' => 'Back\\slash',
        'nationality' => null,
        'is_vip' => true,
        'preferences' => ['pillow' => "firm 'n' high"],
        'external_id' => 'ext-1',
        'channel' => 'booking_com',
    ]);

    $dump = sqlDumpOf(Guest::class, $hotel);
    $insert = collect(explode("\n", $dump))->first(fn ($line) => str_starts_with($line, 'INSERT INTO "guests"'));

    expect($dump)->toStartWith('-- test export')
        ->and($dump)->toContain("-- Matching when the export started: 1\n")
        ->and($dump)->toContain('-- Hotel: Sql Dump Hotel')
        ->and($dump)->toContain("BEGIN;\n")
        ->and($dump)->toEndWith("COMMIT;\n-- Rows written: 1\n")
        ->and($insert)->toContain('"id", "hotel_id"')
        ->and($insert)->toContain("'O''Brien'")
        ->and($insert)->toContain('TRUE')
        ->and($insert)->toContain('NULL')
        ->and($insert)->toContain("firm ''n'' high");
});

it('writes numbers unquoted and leaves out the excluded columns', function () {
    [, $hotel] = sqlAdmin();
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-2', 'channel' => 'booking_com']);
    wp5Booking($hotel, ['guest_id' => $guest->id, 'expected_value' => 60.5, 'pax' => 3, 'context' => ['why' => 'internal']]);

    $dump = sqlDumpOf(Booking::class, $hotel, ['context']);

    expect($dump)->toContain('"guest_id"')
        ->and($dump)->toContain('"activity_id"')
        ->and($dump)->toContain('"reservation_id"')
        ->and($dump)->not->toContain('"context"')
        ->and($dump)->not->toContain('internal')
        ->and($dump)->toContain('60.50')
        ->and($dump)->not->toContain("'60.50'")
        ->and($dump)->toMatch('/, 3, /');
});

it('loads exported guests and bookings into empty tables with every value and link intact', function () {
    [$admin, $hotel] = sqlAdmin();
    $activity = Activity::create(['hotel_id' => $hotel->id, 'name' => 'Kayak', 'price' => 20, 'currency' => 'USD']);

    $guests = collect(["O'Brien", 'أحمد', 'Иван'])->map(fn (string $name) => Guest::create([
        'hotel_id' => $hotel->id,
        'first_name' => $name,
        'email' => Str::lower(Str::random(6)).'@example.com',
        'is_vip' => $name === 'Иван',
        'preferences' => ['note' => "it's \"quoted\""],
        'external_id' => 'ext-'.Str::random(6),
        'channel' => 'booking_com',
    ]));

    foreach ($guests as $guest) {
        wp5Booking($hotel, ['guest_id' => $guest->id, 'activity_id' => $activity->id, 'item_name' => 'Kayak', 'notes' => "Line one\nline 'two'"]);
    }

    $guestDump = sqlExport($this, $admin, 'guest');
    $bookingDump = sqlExport($this, $admin, 'booking');

    DB::statement('CREATE TEMP TABLE guests_rt (LIKE guests INCLUDING DEFAULTS)');
    DB::statement('CREATE TEMP TABLE bookings_rt (LIKE bookings INCLUDING DEFAULTS)');

    // Load the whole dump (values may span lines) into the copies. Its own
    // BEGIN/COMMIT would end the test's transaction, so they are dropped.
    $inserts = fn (string $dump, string $from, string $to) => str_replace(
        ["INSERT INTO \"{$from}\"", "\nBEGIN;\n", "\nCOMMIT;\n"],
        ["INSERT INTO \"{$to}\"", "\n", "\n"],
        $dump,
    );

    DB::unprepared($inserts($guestDump, 'guests', 'guests_rt'));
    DB::unprepared($inserts($bookingDump, 'bookings', 'bookings_rt'));

    // The identity-matching columns are internal and left out (FR-013).
    $internal = ['identity_hash', 'identity_resolved_at'];
    $source = DB::table('guests')->where('hotel_id', $hotel->id)->orderBy('id')->get();
    $loaded = DB::table('guests_rt')->orderBy('id')->get();

    expect($loaded)->toHaveCount(3)
        ->and($source->whereNotNull('identity_hash'))->not->toBeEmpty()
        ->and($loaded->whereNotNull('identity_hash'))->toBeEmpty()
        ->and($loaded->map(fn ($row) => collect((array) $row)->except($internal)->all())->all())
        ->toEqual($source->map(fn ($row) => collect((array) $row)->except($internal)->all())->all())
        ->and($guestDump)->not->toContain('"identity_hash"');

    $sourceBookings = DB::table('bookings')->where('hotel_id', $hotel->id)->orderBy('id')->get()
        ->map(fn ($row) => collect((array) $row)->except('context')->all());
    $loadedBookings = DB::table('bookings_rt')->orderBy('id')->get()
        ->map(fn ($row) => collect((array) $row)->except('context')->all());

    $orphans = DB::table('bookings_rt')->whereNotIn('guest_id', DB::table('guests_rt')->select('id'))->count();

    expect($loadedBookings->all())->toEqual($sourceBookings->all())
        ->and($orphans)->toBe(0)
        ->and(DB::table('bookings_rt')->whereNotNull('context')->count())->toBe(0);
});

it('keeps a hotel name with line breaks inside its comment line', function () {
    [, $hotel] = sqlAdmin();
    $hotel->update(['name' => "Grand\nDROP TABLE guests; --\r\nEnd"]);
    Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-3', 'channel' => 'booking_com']);

    $lines = explode("\n", sqlDumpOf(Guest::class, $hotel->fresh()));

    expect(collect($lines)->first(fn ($line) => str_contains($line, 'DROP TABLE')))
        ->toStartWith('-- Hotel: Grand DROP TABLE guests; -- End')
        ->and(collect($lines)->filter(fn ($line) => $line !== '' && ! str_starts_with($line, '-- ')
            && ! str_starts_with($line, 'INSERT INTO') && ! in_array($line, ['BEGIN;', 'COMMIT;'], true)))->toBeEmpty();
});
