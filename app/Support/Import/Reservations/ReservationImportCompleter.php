<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Models\Hotel;
use App\Models\ReservationImport;
use App\Models\ReservationImportDraft;
use App\Support\Audit\EventLogger;
use Illuminate\Support\Facades\Storage;

/**
 * Ends an import run, either way: final totals, status, the one audit event
 * per run (FR-032), and cleanup of the drafts and uploaded file, so no
 * guest contact data outlives the run.
 */
class ReservationImportCompleter
{
    public function __construct(private readonly ImportOutcomeRecorder $recorder) {}

    public function complete(ReservationImport $import): void
    {
        $this->recorder->refreshTotals($import);
        $import->update(['status' => ImportStatus::COMPLETED, 'stage' => ImportStage::REPORT, 'finished_at' => now()]);
        $this->cleanUp($import);
        $this->audit($import);
    }

    public function fail(ReservationImport $import, string $reason): void
    {
        $this->recorder->settleUnwritten($import, 'Not imported: the import stopped before this row was written. Upload the file again to import it.');
        $this->recorder->refreshTotals($import);
        $import->update(['status' => ImportStatus::FAILED, 'failure_reason' => $reason, 'finished_at' => now()]);
        $this->cleanUp($import);
        $this->audit($import);
    }

    private function cleanUp(ReservationImport $import): void
    {
        ReservationImportDraft::where('reservation_import_id', $import->id)->delete();

        if ($import->file_path !== null) {
            Storage::disk(config('reservation_import.disk'))->delete($import->file_path);
            $import->update(['file_path' => null]);
        }
    }

    /**
     * The event the import has always written, on the hotel, with the run's
     * totals instead of thousands of per-row events. Recorded on behalf of
     * the uploader; with no uploader left (deleted), without an actor.
     */
    private function audit(ReservationImport $import): void
    {
        $hotel = Hotel::findOrFail($import->hotel_id);
        $record = fn () => EventLogger::record($hotel, 'reservations_imported', changes: [
            'reservation_import_id' => $import->id,
            'file_name' => $import->file_name,
            'dry_run' => $import->dry_run,
            'status' => $import->status->value,
            'total_rows' => $import->total_rows,
            'imported' => $import->imported,
            'skipped' => $import->skipped,
            'duplicate' => $import->duplicate,
            'invalid' => $import->invalid,
            'unresolved' => $import->unresolved,
        ]);

        $uploader = $import->uploader;
        $uploader ? EventLogger::actingAs($uploader, $record) : $record();
    }
}
