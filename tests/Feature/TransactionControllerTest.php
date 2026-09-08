<?php

use App\Enums\UserRole;
use App\Models\Hotel;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('API_KEY=test-api-key');
    config(['app.api_key' => 'test-api-key']);
});

function txnCtrlApiHeaders(): array
{
    return ['X-API-KEY' => 'test-api-key'];
}

function txnCtrlAdminWithHotel(): array
{
    $admin = User::factory()->role(UserRole::ADMIN)->create();
    $hotel = Hotel::create([
        'owner_id' => $admin->id,
        'name' => 'Txn Controller Hotel',
        'slug' => 'txn-ctrl-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $admin->update(['hotel_id' => $hotel->id]);

    return [$admin->fresh(), $hotel];
}

function makeTransaction(Hotel $hotel, array $overrides = []): Transaction
{
    return app(TransactionService::class)->record(array_merge([
        'hotel_id' => $hotel->id,
        'item_name' => 'Spa treatment',
        'unit_price' => 80,
        'line_total' => 80,
        'currency' => 'USD',
        'transacted_at' => '2026-09-04 16:00:00',
        'business_date' => '2026-09-04',
        'source_system' => 'import',
        'external_reference' => 'REF-'.uniqid(),
    ], $overrides));
}

it('lists only the caller hotel transactions', function () {
    [$admin, $hotel] = txnCtrlAdminWithHotel();
    [, $otherHotel] = txnCtrlAdminWithHotel();

    $mine = makeTransaction($hotel);
    makeTransaction($otherHotel);

    $this->withHeaders(txnCtrlApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson('/api/transaction')
        ->assertOk()
        ->assertJsonPath('body.meta.total', 1)
        ->assertJsonPath('body.data.0.id', $mine->id);
});

it('forbids showing another hotel transaction', function () {
    [$admin] = txnCtrlAdminWithHotel();
    [, $otherHotel] = txnCtrlAdminWithHotel();
    $theirs = makeTransaction($otherHotel);

    // Same convention as the other resources: route-model binding resolves
    // before the tenant middleware sets context, so the policy is what
    // rejects a cross-hotel id — a 403, not a 404.
    $this->withHeaders(txnCtrlApiHeaders())->actingAs($admin, 'sanctum')
        ->getJson("/api/transaction/{$theirs->id}")
        ->assertStatus(403);
});

it('reverses a transaction through the endpoint', function () {
    [$admin, $hotel] = txnCtrlAdminWithHotel();
    $txn = makeTransaction($hotel, ['line_total' => 80]);

    $this->withHeaders(txnCtrlApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/transaction/{$txn->id}/reverse", ['reason' => 'guest disputed the charge'])
        ->assertCreated()
        ->assertJsonPath('body.reverses_transaction_id', $txn->id);

    expect((float) Transaction::where('hotel_id', $hotel->id)->sum('line_total'))->toBe(0.0);
});

it('rejects a reversal without a reason', function () {
    [$admin, $hotel] = txnCtrlAdminWithHotel();
    $txn = makeTransaction($hotel);

    $this->withHeaders(txnCtrlApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/transaction/{$txn->id}/reverse", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('refuses to reverse the same transaction twice through the endpoint', function () {
    [$admin, $hotel] = txnCtrlAdminWithHotel();
    $txn = makeTransaction($hotel);

    app(TransactionService::class)->reverse($txn, 'first');

    $this->withHeaders(txnCtrlApiHeaders())->actingAs($admin, 'sanctum')
        ->postJson("/api/transaction/{$txn->id}/reverse", ['reason' => 'again'])
        ->assertStatus(422);
});

it('blocks a non-admin from listing transactions', function () {
    $worker = User::factory()->role(UserRole::EMPLOYEE)->create();

    $this->withHeaders(txnCtrlApiHeaders())->actingAs($worker, 'sanctum')
        ->getJson('/api/transaction')
        ->assertStatus(403);
});
