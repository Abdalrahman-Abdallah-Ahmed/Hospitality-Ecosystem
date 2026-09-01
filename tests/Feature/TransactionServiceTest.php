<?php

use App\Enums\EvidenceLevel;
use App\Models\Hotel;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ledgerHotel(): Hotel
{
    $owner = User::factory()->create();
    $hotel = Hotel::create([
        'owner_id' => $owner->id,
        'name' => 'Ledger Hotel',
        'slug' => 'ledger-hotel-'.uniqid(),
        'currency' => 'USD',
    ]);
    $owner->update(['hotel_id' => $hotel->id]);

    return $hotel;
}

function recordTransaction(Hotel $hotel, array $overrides = []): Transaction
{
    return app(TransactionService::class)->record(array_merge([
        'hotel_id' => $hotel->id,
        'item_name' => 'Spa treatment',
        'quantity' => 1,
        'unit_price' => 80,
        'currency' => 'USD',
        'transacted_at' => '2026-09-04 16:00:00',
        'business_date' => '2026-09-04',
        'source_system' => 'import',
        'external_reference' => 'REF-'.uniqid(),
        'evidence_level' => EvidenceLevel::L1->value,
    ], $overrides));
}

it('computes line_total from unit_price and quantity when absent', function () {
    $hotel = ledgerHotel();

    $txn = recordTransaction($hotel, ['quantity' => 3, 'unit_price' => 10, 'discount_amount' => 5]);

    expect((float) $txn->line_total)->toBe(25.0);
});

it('reverses a transaction without deleting the original', function () {
    $hotel = ledgerHotel();
    $original = recordTransaction($hotel, ['unit_price' => 100, 'line_total' => 100]);

    $reversal = app(TransactionService::class)->reverse($original, 'guest disputed the charge');

    expect(Transaction::withoutGlobalScope('hotel')->find($original->id))->not->toBeNull()
        ->and((float) $reversal->line_total)->toBe(-100.0)
        ->and($reversal->reverses_transaction_id)->toBe($original->id)
        ->and((float) Transaction::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->sum('line_total'))->toBe(0.0);
});

it('refuses to reverse a transaction twice', function () {
    $hotel = ledgerHotel();
    $original = recordTransaction($hotel);

    app(TransactionService::class)->reverse($original, 'first correction');

    expect(fn () => app(TransactionService::class)->reverse($original, 'second correction'))
        ->toThrow(RuntimeException::class);
});

it('refuses to reverse a reversal', function () {
    $hotel = ledgerHotel();
    $original = recordTransaction($hotel);
    $reversal = app(TransactionService::class)->reverse($original, 'correction');

    expect(fn () => app(TransactionService::class)->reverse($reversal, 'undo the undo'))
        ->toThrow(RuntimeException::class);
});

it('blocks updates to a persisted transaction', function () {
    $hotel = ledgerHotel();
    $txn = recordTransaction($hotel);

    expect(fn () => $txn->update(['line_total' => 999]))->toThrow(RuntimeException::class);
});

it('blocks deletes of a persisted transaction', function () {
    $hotel = ledgerHotel();
    $txn = recordTransaction($hotel);

    expect(fn () => $txn->delete())->toThrow(RuntimeException::class);
});
