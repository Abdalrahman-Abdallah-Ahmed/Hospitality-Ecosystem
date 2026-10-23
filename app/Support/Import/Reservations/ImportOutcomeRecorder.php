<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;
use App\Models\ReservationImport;
use App\Models\ReservationImportDraft;
use App\Models\ReservationImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the reconciliation record: one row result per data row, the
 * drafts still to be written, and the import's totals.
 */
class ImportOutcomeRecorder
{
    /**
     * Saves the prepare phase's result: a draft per pending reservation
     * (unless `$withDrafts` is false, as on a dry run) and a result for
     * every row, linked to its draft while it waits.
     */
    public function recordPlan(ReservationImport $import, ImportPlan $plan, bool $withDrafts = true): void
    {
        DB::transaction(function () use ($import, $plan, $withDrafts) {
            $now = now();
            $draftIds = [];

            if ($withDrafts) {
                foreach (array_chunk($plan->drafts, 200, preserve_keys: true) as $chunk) {
                    $records = [];
                    foreach ($chunk as $position => $draft) {
                        $draftIds[$position] = (string) Str::uuid();
                        $records[] = [
                            'id' => $draftIds[$position],
                            'hotel_id' => $import->hotel_id,
                            'reservation_import_id' => $import->id,
                            'position' => $position,
                            'payload' => json_encode($draft),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    ReservationImportDraft::insert($records);
                }
            }

            $draftOfRow = [];
            foreach ($plan->drafts as $position => $draft) {
                foreach ($draft['rows'] as $rowNumber) {
                    $draftOfRow[$rowNumber] = $draftIds[$position] ?? null;
                }
            }

            foreach (array_chunk($plan->rows, 500) as $chunk) {
                ReservationImportRow::insert(array_map(fn (array $row) => [
                    'id' => (string) Str::uuid(),
                    'hotel_id' => $import->hotel_id,
                    'reservation_import_id' => $import->id,
                    'row_number' => $row['row_number'],
                    'external_reference' => $row['external_reference'],
                    'outcome' => $row['outcome']?->value,
                    'stage' => $row['stage']->value,
                    'fields' => json_encode($row['fields']),
                    'reason' => $row['reason'],
                    'warnings' => json_encode($row['warnings']),
                    'reservation_id' => $row['reservation_id'],
                    'draft_id' => $draftOfRow[$row['row_number']] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }
        });
    }

    /**
     * Gives a written (or failed) draft's rows their outcome, appends the
     * warnings found while writing, and drops the draft.
     *
     * @param  list<array{row: int, code: string, message: string}>  $warnings
     * @param  list<string>  $fields
     */
    public function settleDraft(ReservationImportDraft $draft, ImportRowOutcome $outcome, ?string $reservationId, array $warnings = [], ?string $reason = null, array $fields = []): void
    {
        ReservationImportRow::where('draft_id', $draft->id)->update([
            'outcome' => $outcome->value,
            'stage' => ImportStage::IMPORT->value,
            'reservation_id' => $reservationId,
            'reason' => $reason,
            'fields' => json_encode($fields),
            'draft_id' => null,
        ]);

        foreach ($warnings as $warning) {
            DB::update(
                'UPDATE reservation_import_rows SET warnings = warnings || ?::jsonb, updated_at = now() WHERE reservation_import_id = ? AND row_number = ?',
                [json_encode([['code' => $warning['code'], 'message' => $warning['message']]]), $draft->reservation_import_id, $warning['row']],
            );
        }

        $draft->delete();
    }

    /**
     * Rows still waiting in a draft when a run stops: reported invalid with
     * `$reason`, so the totals and the report still account for every row.
     */
    public function settleUnwritten(ReservationImport $import, string $reason): void
    {
        ReservationImportRow::where('reservation_import_id', $import->id)
            ->whereNull('outcome')
            ->update([
                'outcome' => ImportRowOutcome::INVALID->value,
                'stage' => ImportStage::IMPORT->value,
                'reason' => $reason,
                'draft_id' => null,
            ]);
    }

    /**
     * Recounts the five totals and the rows processed from the row results.
     */
    public function refreshTotals(ReservationImport $import): void
    {
        $counts = ReservationImportRow::where('reservation_import_id', $import->id)
            ->whereNotNull('outcome')
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $totals = [];
        foreach (ImportRowOutcome::cases() as $outcome) {
            $totals[$outcome->value] = (int) ($counts[$outcome->value] ?? 0);
        }

        $import->update([...$totals, 'processed_rows' => array_sum($totals)]);
    }
}
