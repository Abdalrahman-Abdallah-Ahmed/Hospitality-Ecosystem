<?php

use App\Enums\ChargeModel;
use App\Enums\MeterFeature;
use App\Enums\Permission;
use App\Http\Resources\GuestResource;
use App\Http\Resources\StayResource;
use App\Models\Hotel;
use App\Models\MeterEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// The flag is deliberately not set here: the off cases exercise the default.
beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function ffEnable(): void
{
    config(['features.transactions' => true]);
}

function ffCall($test, User $user, string $method, string $uri, array $payload = []): TestResponse
{
    return $test->withHeaders(['X-API-KEY' => 'test-api-key'])->actingAs($user, 'sanctum')->json($method, $uri, $payload);
}

function ffCsv(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'transactions').'.csv';
    file_put_contents($path, "external_reference,item_name,line_total,currency,transacted_at,business_date\nFF-1,Spa,80,USD,2026-09-04 16:00:00,2026-09-04\n");

    return new UploadedFile($path, 'transactions.csv', 'text/csv', null, true);
}

/**
 * Every transaction endpoint, against one ledger row of the hotel.
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function ffEndpoints(Transaction $transaction): array
{
    return [
        ['GET', '/api/transaction', []],
        ['GET', "/api/transaction/{$transaction->id}", []],
        ['POST', '/api/transaction/import', ['file' => ffCsv()]],
        ['POST', "/api/transaction/{$transaction->id}/reverse", ['reason' => 'guest disputed the charge']],
    ];
}

/**
 * @return array{0: User, 1: Hotel, 2: Transaction}
 */
function ffHotelWithLedgerRow(): array
{
    [$admin, $hotel] = wp5AdminWithHotel();

    return [$admin, $hotel, wp5Transaction($hotel)];
}

it('is off when nothing is configured', function () {
    expect(config('features.transactions'))->toBeFalse();
});

it('refuses every transaction endpoint to every role while the ledger is off', function (string $actor) {
    [$admin, $hotel, $transaction] = ffHotelWithLedgerRow();

    $user = match ($actor) {
        'super admin' => knSuperAdmin(),
        'admin' => $admin,
        'employee with every transactions permission' => fdEmployee($hotel, [
            Permission::TRANSACTIONS_VIEW, Permission::TRANSACTIONS_IMPORT, Permission::TRANSACTIONS_REVERSE,
        ]),
    };

    foreach (ffEndpoints($transaction) as [$method, $uri, $payload]) {
        ffCall($this, $user, $method, $uri, $payload)
            ->assertNotFound()
            ->assertJsonPath('message', 'The transactions feature is not enabled.');
    }

    expect(Transaction::withoutGlobalScopes()->count())->toBe(1)
        ->and(MeterEvent::where('feature_code', MeterFeature::TRANSACTION_ROWS_IMPORTED->value)->count())->toBe(0);
})->with(['super admin', 'admin', 'employee with every transactions permission']);

it('answers "not enabled" for an id that does not exist', function () {
    [$admin] = wp5AdminWithHotel();

    ffCall($this, $admin, 'GET', '/api/transaction/'.fake()->uuid())
        ->assertNotFound()
        ->assertJsonPath('message', 'The transactions feature is not enabled.');
});

it('still asks for authentication first', function () {
    $this->withHeaders(['X-API-KEY' => 'test-api-key'])->getJson('/api/transaction')->assertUnauthorized();
});

it('leaves settlement out of conversion analytics while the ledger is off', function () {
    [$admin] = wp5AdminWithHotel();

    $body = ffCall($this, $admin, 'GET', '/api/analytics/conversion')->assertOk()->json('body');

    expect($body)->not->toHaveKeys(['settlement_rate', 'settled_value', 'settled_value_by_currency']);
});

it('leaves transactions out of the booking while the ledger is off', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    $booking = wp5Booking($hotel, ['guest_id' => abGuest($hotel)->id]);

    $body = ffCall($this, $admin, 'GET', "/api/booking/{$booking->id}")->assertOk()->json('body');

    expect($body)->not->toHaveKey('transactions');
});

it('writes no ledger row anywhere in an MVP stay while the ledger is off', function () {
    [$hotel, $type, $rooms] = fdHotel(1);
    $admin = $hotel->owner;
    $reservation = fdBook($hotel, $type, [$rooms[0]->id]);
    [$stay] = fdStays($reservation);

    fdPost($this, $admin, "/api/stays/{$stay->id}/check-in")->assertOk();

    $booking = abBook($hotel, abActivity($hotel), now($hotel->timezone)->toDateString(), attributes: ['stay_id' => $stay->id]);
    fdPost($this, $admin, "/api/booking/{$booking->id}/status", ['status' => 'confirmed'])->assertOk();
    fdPost($this, $admin, "/api/booking/{$booking->id}/status", ['status' => 'realised'])->assertOk();

    fdPost($this, $admin, "/api/stays/{$stay->id}/check-out")->assertOk();

    expect(Transaction::withoutGlobalScopes()->count())->toBe(0);
});

it('works as before once the ledger is turned on', function () {
    ffEnable();
    [$admin, $hotel, $transaction] = ffHotelWithLedgerRow();

    ffCall($this, $admin, 'GET', '/api/transaction')
        ->assertOk()
        ->assertJsonPath('body.data.0.id', $transaction->id);

    $booking = wp5Booking($hotel, ['guest_id' => abGuest($hotel)->id]);

    expect(ffCall($this, $admin, 'GET', '/api/analytics/conversion')->assertOk()->json('body'))->toHaveKey('settlement_rate')
        ->and(ffCall($this, $admin, 'GET', "/api/booking/{$booking->id}")->assertOk()->json('body'))->toHaveKey('transactions');
});

it('still checks permissions and tenancy once the ledger is turned on', function () {
    ffEnable();
    [, $hotel, $transaction] = ffHotelWithLedgerRow();
    [$otherAdmin] = wp5AdminWithHotel();

    ffCall($this, fdEmployee($hotel, [Permission::TRANSACTIONS_IMPORT]), 'GET', '/api/transaction')->assertForbidden();

    ffCall($this, $otherAdmin, 'GET', "/api/transaction/{$transaction->id}")->assertNotFound();
    expect(ffCall($this, $otherAdmin, 'GET', '/api/transaction')->assertOk()->json('body.data'))->toBe([]);
});

it('tells the frontend whether transactions are on, and cannot be switched through the hotel', function () {
    [$admin, $hotel] = wp5AdminWithHotel();

    ffCall($this, $admin, 'GET', "/api/hotel/{$hotel->id}")
        ->assertOk()
        ->assertJsonPath('body.transactions_enabled', false);

    ffCall($this, $admin, 'PATCH', "/api/hotel/{$hotel->id}", ['transactions_enabled' => true]);

    ffCall($this, $admin, 'GET', "/api/hotel/{$hotel->id}")->assertJsonPath('body.transactions_enabled', false);
    ffCall($this, $admin, 'GET', '/api/transaction')->assertNotFound();

    ffEnable();

    ffCall($this, $admin, 'GET', "/api/hotel/{$hotel->id}")->assertJsonPath('body.transactions_enabled', true);
});

it('keeps the ledger out of stay and guest responses even when it was loaded', function () {
    [$hotel, $type, $rooms] = fdHotel(1);
    [$stay] = fdStays(fdBook($hotel, $type, [$rooms[0]->id]));
    wp5Transaction($hotel, ['stay_id' => $stay->id, 'guest_id' => $stay->guest_id]);

    $stay->load('transactions', 'guest.transactions');

    expect(StayResource::make($stay)->resolve())->not->toHaveKey('transactions')
        ->and(GuestResource::make($stay->guest)->resolve())->not->toHaveKey('transactions');

    ffEnable();

    expect(StayResource::make($stay)->resolve())->toHaveKey('transactions')
        ->and(GuestResource::make($stay->guest)->resolve())->toHaveKey('transactions');
});

it('drops the settlement caveat from the notes while the ledger is off', function () {
    [$admin, $hotel] = wp5AdminWithHotel();
    [$recommendation, $guest, $activity] = wp5Recommendation($hotel);
    wp5Booking($hotel, [
        'guest_id' => $guest->id,
        'activity_id' => $activity->id,
        'recommendation_id' => $recommendation->id,
        'charge_model' => ChargeModel::INCLUDED->value,
    ]);

    expect(ffCall($this, $admin, 'GET', '/api/analytics/conversion')->json('body.notes'))
        ->not->toContain('settlement rate');

    ffEnable();

    expect(ffCall($this, $admin, 'GET', '/api/analytics/conversion')->json('body.notes'))
        ->toContain('excluded from the settlement rate');
});

it('offers the transaction permissions to the role editor only while the ledger is on', function () {
    [$admin] = wp5AdminWithHotel();
    $groups = fn () => collect(ffCall($this, $admin, 'GET', '/api/permissions')->assertOk()->json('body.groups'))->pluck('group');

    expect($groups())->not->toContain('transactions');

    ffEnable();

    expect($groups())->toContain('transactions');
});

it('refuses to read a feature that is not configured', function () {
    Features::enabled('transaction');
})->throws(InvalidArgumentException::class, 'Unknown feature [transaction]');
