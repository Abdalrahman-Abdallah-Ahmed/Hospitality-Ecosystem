<?php

namespace App\Services;

use App\Enums\EvidenceLevel;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only sanctioned way to write to the ledger. Two operations, both
 * append-only: record() adds a row, reverse() adds a negated row that points
 * back at the original. There is deliberately no update().
 */
class TransactionService
{
    /**
     * Append one transaction. When the caller supplies a unit_price but no
     * line_total, the line total is derived (unit_price x quantity, less any
     * discount) — line_total is the field every report sums, so it must
     * always be populated.
     */
    public function record(array $data): Transaction
    {
        $quantity = (int) ($data['quantity'] ?? 1) ?: 1;

        if (! isset($data['line_total']) && isset($data['unit_price'])) {
            $data['line_total'] = round(
                ((float) $data['unit_price'] * $quantity) - (float) ($data['discount_amount'] ?? 0),
                2
            );
        }

        $data['quantity'] = $quantity;
        $data['line_total'] = $data['line_total'] ?? 0;

        return Transaction::create($data);
    }

    /**
     * Correct a transaction by appending its negation. The original row is
     * never touched; the two sum to zero and the history stays intact. A
     * reversal cannot itself be reversed, and a transaction can only be
     * reversed once.
     */
    public function reverse(Transaction $original, string $reason): Transaction
    {
        if ($original->reverses_transaction_id !== null) {
            throw new RuntimeException('A reversal cannot itself be reversed.');
        }

        if ($original->reversals()->exists()) {
            throw new RuntimeException('This transaction has already been reversed.');
        }

        $reversal = Transaction::create([
            'hotel_id' => $original->hotel_id,
            'guest_id' => $original->guest_id,
            'stay_id' => $original->stay_id,
            'room_id' => $original->room_id,
            'activity_id' => $original->activity_id,
            'item_name' => 'Reversal: '.$original->item_name,
            'revenue_center' => $original->revenue_center,
            'department' => $original->department,
            'quantity' => -$original->quantity,
            'unit_price' => $original->unit_price,
            'line_total' => -(float) $original->line_total,
            'discount_amount' => -(float) $original->discount_amount,
            'currency' => $original->currency,
            'transacted_at' => Carbon::now(),
            'business_date' => Carbon::now()->toDateString(),
            'seller_reference' => $original->seller_reference,
            'sold_by_user_id' => $original->sold_by_user_id,
            'source_system' => TransactionSource::MANUAL->value,
            'external_reference' => 'reversal:'.$original->id,
            'evidence_level' => ($original->evidence_level ?? EvidenceLevel::L1)->value,
            'reverses_transaction_id' => $original->id,
            'raw_payload' => ['reason' => $reason, 'reverses' => $original->id],
        ]);

        // The reversal row logs its own transaction.created; this records the
        // correction against the original so its history shows it was undone.
        EventLogger::record($original, 'reversed', reason: $reason);

        return $reversal;
    }
}
