<?php

namespace App\Imports;

use App\Enums\ActorKind;
use App\Enums\EvidenceLevel;
use App\Enums\MeterFeature;
use App\Enums\TransactionSource;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Transaction;
use App\Services\Metering\MeteringService;
use App\Services\TransactionAttributionService;
use App\Services\TransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Throwable;

/**
 * Bulk-loads POS/PMS transaction rows into the ledger, scoped to one hotel.
 *
 * Every row is accounted for: imported, counted as a duplicate, or reported
 * as rejected with a reason — nothing is silently dropped. Attribution runs
 * per row through the stay window; a row that cannot be tied to a guest is
 * still imported, but unattributed and marked L4. The final attributed vs
 * unattributed count is the import's data-quality metric.
 */
class TransactionsImport implements ToCollection, WithHeadingRow
{
    public int $read = 0;

    public int $imported = 0;

    public int $duplicates = 0;

    /** @var array<int, array{row: int, reason: string}> */
    public array $rejected = [];

    public int $attributed = 0;

    public int $unattributed = 0;

    /** Rows whose booking_reference resolved to a real booking. */
    public int $booking_links = 0;

    /**
     * Rows carrying a booking_reference that matched nothing in this hotel.
     * The transaction is still imported and keeps the reference for
     * provenance — a code we cannot resolve is a gap worth counting, not a
     * value worth discarding.
     */
    public int $unknown_booking_references = 0;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly TransactionSource $source = TransactionSource::IMPORT,
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            // +2: 1-based, plus the heading row consumed by WithHeadingRow.
            $rowNumber = $index + 2;

            try {
                $this->importRow($row, $rowNumber);
            } catch (Throwable $e) {
                $this->rejected[] = ['row' => $rowNumber, 'reason' => $e->getMessage()];
            }
        }

        // One event for the batch, never one per row: a 10,000-row upload is
        // a single import, and metering inside the loop would put 10,000 rows
        // in the largest table in the system to record one action.
        $metering = app(MeteringService::class);

        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $this->hotel,
            feature: MeterFeature::TRANSACTION_ROWS_IMPORTED,
            quantity: $this->imported,
            metadata: [
                'read' => $this->read,
                'duplicates' => $this->duplicates,
                'rejected' => count($this->rejected),
            ],
            actorKind: ActorKind::IMPORT,
        ));
    }

    private function importRow(Collection $row, int $rowNumber): void
    {
        $this->read++;

        $itemName = trim((string) ($row['item_name'] ?? ''));
        $transactedAt = trim((string) ($row['transacted_at'] ?? ''));
        $unitPrice = $this->number($row['unit_price'] ?? null);
        $lineTotal = $this->number($row['line_total'] ?? null);
        $amount = $this->number($row['amount'] ?? null);

        if ($itemName === '' || $transactedAt === '' || ($unitPrice === null && $lineTotal === null && $amount === null)) {
            $this->rejected[] = [
                'row' => $rowNumber,
                'reason' => 'Missing required field(s): item_name, transacted_at, or an amount (unit_price / line_total / amount).',
            ];

            return;
        }

        $externalReference = trim((string) ($row['external_reference'] ?? '')) ?: null;

        if ($externalReference !== null && Transaction::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('source_system', $this->source->value)
            ->where('external_reference', $externalReference)
            ->exists()
        ) {
            $this->duplicates++;

            return;
        }

        $transactedAtCarbon = Carbon::parse($transactedAt);
        $evidenceLevel = EvidenceLevel::L1;

        // Never store an ambiguous "amount". If the file gives an explicit
        // unit_price or line_total, trust it. If it only gives a single
        // undifferentiated "amount", store it as line_total, leave unit_price
        // null, and downgrade the row — the figure is unverified.
        if ($lineTotal === null && $unitPrice === null) {
            $lineTotal = $amount;
            $evidenceLevel = EvidenceLevel::L4;
        }

        $businessDate = trim((string) ($row['business_date'] ?? '')) ?: $transactedAtCarbon->toDateString();

        $roomNumber = trim((string) ($row['room_number'] ?? ''));
        $stay = null;

        if ($roomNumber !== '') {
            $stay = app(TransactionAttributionService::class)
                ->resolveStay($roomNumber, $transactedAtCarbon, $this->hotel);
        }

        if ($stay) {
            $this->attributed++;
        } else {
            // An honest gap beats a confident guess.
            $this->unattributed++;
            $evidenceLevel = EvidenceLevel::L4;
        }

        $activityName = trim((string) ($row['activity_name'] ?? ''));
        $activityId = $activityName === '' ? null : Activity::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('name', $activityName)
            ->value('id');

        $booking = $this->resolveBooking($row);

        app(TransactionService::class)->record([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $stay?->guest_id,
            'stay_id' => $stay?->id,
            'room_id' => $stay?->room_id,
            'activity_id' => $activityId,
            'item_name' => $itemName,
            'revenue_center' => trim((string) ($row['revenue_center'] ?? '')) ?: null,
            'department' => trim((string) ($row['department'] ?? '')) ?: null,
            'quantity' => (int) ($row['quantity'] ?? 1) ?: 1,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'discount_amount' => $this->number($row['discount_amount'] ?? null) ?? 0,
            'currency' => trim((string) ($row['currency'] ?? '')) ?: $this->hotel->currency,
            'transacted_at' => $transactedAtCarbon,
            'business_date' => $businessDate,
            'seller_reference' => trim((string) ($row['seller_reference'] ?? '')) ?: null,
            'source_system' => $this->source->value,
            'external_reference' => $externalReference,
            'booking_id' => $booking?->id,
            'booking_reference' => $this->bookingReference($row),
            'evidence_level' => $evidenceLevel->value,
            'raw_payload' => $row->toArray(),
        ]);

        $this->imported++;
    }

    /**
     * Ties a payment back to the commitment that produced it, by the code the
     * guest quoted at the desk. Direct reference only — there is deliberately
     * no inference here. A row with no code is a walk-up sale, which is
     * normal, and is left unlinked rather than guessed at.
     */
    private function resolveBooking(Collection $row): ?Booking
    {
        $reference = $this->bookingReference($row);

        if ($reference === null) {
            return null;
        }

        $booking = Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('reference', $reference)
            ->first();

        if ($booking) {
            $this->booking_links++;
        } else {
            $this->unknown_booking_references++;
        }

        return $booking;
    }

    private function bookingReference(Collection $row): ?string
    {
        return strtoupper(trim((string) ($row['booking_reference'] ?? ''))) ?: null;
    }

    /**
     * A blank cell is "not provided" (null), not zero — the two mean
     * different things for money fields.
     */
    private function number(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (float) $value;
    }
}
