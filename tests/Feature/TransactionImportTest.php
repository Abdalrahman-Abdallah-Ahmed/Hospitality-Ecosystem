<?php

use App\Enums\StayStatus;
use App\Enums\UserRole;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\Stay;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function txnApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function txnAdminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Transaction Import Hotel',
        'slug' => 'txn-import-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

/**
 * @param  array<int, array<string, string>>  $rows
 */
function importTxnCsv(array $rows): UploadedFile
{
    $columns = ['external_reference', 'room_number', 'item_name', 'unit_price', 'line_total', 'amount', 'quantity', 'currency', 'transacted_at', 'business_date', 'activity_name'];
    $lines = array_map(
        fn (array $row) => implode(',', array_map(fn ($col) => $row[$col] ?? '', $columns)),
        $rows
    );
    $content = implode("\n", [implode(',', $columns), ...$lines]);

    $path = tempnam(sys_get_temp_dir(), 'transactions').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, 'transactions.csv', 'text/csv', null, true);
}

function checkedInStay(Hotel $hotel, string $roomNumber): Stay
{
    $guest = Guest::create(['hotel_id' => $hotel->id, 'external_id' => 'ext-'.uniqid(), 'channel' => 'booking_com']);
    $room = Room::create(['hotel_id' => $hotel->id, 'room_number' => $roomNumber]);

    return Stay::create([
        'hotel_id' => $hotel->id,
        'guest_id' => $guest->id,
        'room_id' => $room->id,
        'planned_arrival_date' => '2026-09-01',
        'planned_departure_date' => '2026-09-10',
        'checked_in_at' => '2026-09-01 14:00',
        'checked_out_at' => null,
        'status' => StayStatus::IN_HOUSE,
    ]);
}

function importTransactions(User $admin, UploadedFile $file)
{
    return test()->withHeaders(txnApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson('/api/transaction/import', ['file' => $file]);
}

it('rejects an unauthenticated import request', function () {
    $this->withHeaders(txnApiHeaders())->postJson('/api/transaction/import')->assertStatus(401);
});

it('rejects a non-admin user from importing transactions', function () {
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(txnApiHeaders())->actingAs($worker, 'sanctum')
        ->postJson('/api/transaction/import', ['file' => importTxnCsv([
            ['item_name' => 'Spa', 'line_total' => '50', 'transacted_at' => '2026-09-04 12:00'],
        ])])
        ->assertStatus(403);
});

it('does not import the same external_reference twice', function () {
    [$admin, $hotel] = txnAdminWithHotel();

    $rows = [['external_reference' => 'POS-1', 'item_name' => 'Spa', 'line_total' => '50', 'transacted_at' => '2026-09-04 12:00']];

    importTransactions($admin, importTxnCsv($rows))->assertOk()->assertJsonPath('body.imported', 1);

    $second = importTransactions($admin, importTxnCsv($rows))->assertOk();
    $second->assertJsonPath('body.imported', 0)->assertJsonPath('body.duplicates', 1);

    expect(Transaction::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('leaves stay_id and guest_id null and marks L4 when no stay matches the room', function () {
    [$admin, $hotel] = txnAdminWithHotel();

    importTransactions($admin, importTxnCsv([
        ['room_number' => '999', 'item_name' => 'Minibar', 'line_total' => '12', 'transacted_at' => '2026-09-04 21:00'],
    ]))->assertOk()->assertJsonPath('body.unattributed', 1)->assertJsonPath('body.attributed', 0);

    $txn = Transaction::where('hotel_id', $hotel->id)->first();
    expect($txn->stay_id)->toBeNull()
        ->and($txn->guest_id)->toBeNull()
        ->and($txn->evidence_level->value)->toBe('L4');
});

it('attributes a purchase to the guest in the room within their stay window', function () {
    [$admin, $hotel] = txnAdminWithHotel();
    $stay = checkedInStay($hotel, '304');

    importTransactions($admin, importTxnCsv([
        ['room_number' => '304', 'item_name' => 'Dive trip', 'line_total' => '120', 'transacted_at' => '2026-09-04 10:00'],
    ]))->assertOk()->assertJsonPath('body.attributed', 1);

    $txn = Transaction::where('hotel_id', $hotel->id)->first();
    expect($txn->stay_id)->toBe($stay->id)
        ->and($txn->guest_id)->toBe($stay->guest_id);
});

it('reports rejected rows instead of skipping them', function () {
    [$admin, $hotel] = txnAdminWithHotel();

    $response = importTransactions($admin, importTxnCsv([
        ['item_name' => 'Valid', 'line_total' => '10', 'transacted_at' => '2026-09-04 12:00'],
        ['item_name' => '', 'line_total' => '10', 'transacted_at' => '2026-09-04 12:00'],
    ]))->assertOk();

    $response->assertJsonPath('body.read', 2)
        ->assertJsonPath('body.imported', 1)
        ->assertJsonCount(1, 'body.rejected');

    expect(Transaction::where('hotel_id', $hotel->id)->count())->toBe(1);
});

it('marks a row L4 and leaves unit_price null when the source gives only an ambiguous amount', function () {
    [$admin, $hotel] = txnAdminWithHotel();
    checkedInStay($hotel, '305');

    importTransactions($admin, importTxnCsv([
        ['room_number' => '305', 'item_name' => 'Restaurant', 'amount' => '50', 'transacted_at' => '2026-09-04 20:00'],
    ]))->assertOk()->assertJsonPath('body.attributed', 1);

    $txn = Transaction::where('hotel_id', $hotel->id)->first();
    expect($txn->unit_price)->toBeNull()
        ->and((float) $txn->line_total)->toBe(50.0)
        ->and($txn->evidence_level->value)->toBe('L4');
});

it('sums line_total, not unit_price, for revenue', function () {
    [$admin, $hotel] = txnAdminWithHotel();

    importTransactions($admin, importTxnCsv([
        ['item_name' => 'Excursion', 'unit_price' => '10', 'line_total' => '30', 'quantity' => '3', 'transacted_at' => '2026-09-04 09:00'],
    ]))->assertOk();

    $revenue = (float) Transaction::where('hotel_id', $hotel->id)->sum('line_total');
    expect($revenue)->toBe(30.0);
});

it('returns a summary with attributed and unattributed counts', function () {
    [$admin, $hotel] = txnAdminWithHotel();
    checkedInStay($hotel, '304');

    importTransactions($admin, importTxnCsv([
        ['room_number' => '304', 'item_name' => 'Dive', 'line_total' => '120', 'transacted_at' => '2026-09-04 10:00'],
        ['room_number' => '999', 'item_name' => 'Minibar', 'line_total' => '9', 'transacted_at' => '2026-09-04 22:00'],
    ]))->assertOk()
        ->assertJsonPath('body.read', 2)
        ->assertJsonPath('body.imported', 2)
        ->assertJsonPath('body.attributed', 1)
        ->assertJsonPath('body.unattributed', 1);
});
