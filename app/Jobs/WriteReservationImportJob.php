<?php

namespace App\Jobs;

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStatus;
use App\Enums\QueueLane;
use App\Models\Hotel;
use App\Models\ReservationImport;
use App\Models\ReservationImportDraft;
use App\Support\Audit\EventLogger;
use App\Support\Import\Reservations\DraftRejected;
use App\Support\Import\Reservations\ImportOutcomeRecorder;
use App\Support\Import\Reservations\ReservationImportCompleter;
use App\Support\Import\Reservations\ReservationImportWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The write phase of a reservation import: writes the next chunk of drafts
 * in file order, one reservation per transaction (FR-024), then queues
 * itself for the rest, so no single job runs anywhere near the queue's
 * retry_after. When no draft is left it completes the import.
 *
 * A draft that cannot be saved is rolled back and reported invalid with a
 * readable reason; the run carries on with the next one. A draft's
 * reservation, row outcomes and removal are committed together, so a job
 * that dies mid-chunk resumes exactly where it stopped.
 */
class WriteReservationImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public string $importId)
    {
        // Imports run in their own lane, one at a time (spec 017 FR-020).
        $this->onQueue(QueueLane::IMPORTS->value);
    }

    public function handle(ReservationImportWriter $writer, ImportOutcomeRecorder $recorder, ReservationImportCompleter $completer): void
    {
        // Unscoped on purpose: found by id before its hotel is known; the work itself runs in runForHotel.
        $import = TenantContext::withoutScope(fn () => ReservationImport::find($this->importId));
        if (! $import || $import->status !== ImportStatus::RUNNING) {
            return;
        }

        $remaining = TenantContext::runForHotel($import->hotel_id, function () use ($import, $writer, $recorder, $completer) {
            $hotel = Hotel::findOrFail($import->hotel_id);
            $drafts = ReservationImportDraft::where('reservation_import_id', $import->id)
                ->orderBy('position')
                ->limit(max(1, (int) config('reservation_import.write_chunk_size')))
                ->get();

            foreach ($drafts as $draft) {
                DB::transaction(fn () => $this->writeDraft($draft, $hotel, $writer, $recorder));
            }

            $recorder->refreshTotals($import);

            $left = ReservationImportDraft::where('reservation_import_id', $import->id)->exists();
            if (! $left) {
                $completer->complete($import->fresh());
            }

            return $left;
        });

        if ($remaining) {
            self::dispatch($import->id);
        }
    }

    private function writeDraft(ReservationImportDraft $draft, Hotel $hotel, ReservationImportWriter $writer, ImportOutcomeRecorder $recorder): void
    {
        try {
            // One summary event per import instead of one per record (FR-032).
            $result = EventLogger::withoutRecording(fn () => $writer->write($draft->payload, $hotel));
        } catch (Throwable $e) {
            Log::warning('Reservation import could not save a reservation.', [
                'reservation_import_id' => $draft->reservation_import_id,
                'position' => $draft->position,
                'exception' => $e,
            ]);

            [$reason, $fields] = self::readable($e);
            $recorder->settleDraft($draft, ImportRowOutcome::INVALID, null, [], $reason, $fields);

            return;
        }

        $recorder->settleDraft($draft, $result['outcome'], $result['reservation']->id, $result['warnings'], $result['reason']);
    }

    /**
     * A reason staff can act on. Validation failures from the shared write
     * path carry their own message; anything else is logged in full and
     * reported plainly, never as an exception class or SQL.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function readable(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            $errors = $e->errors();

            return ['Could not be saved: '.collect($errors)->flatten()->first(), array_keys($errors)];
        }

        if ($e instanceof DraftRejected) {
            return ['Could not be saved: '.$e->getMessage(), []];
        }

        return ['Could not be saved because of an unexpected error. It has been logged; try importing the file again.', []];
    }

    public function failed(?Throwable $exception): void
    {
        // Unscoped on purpose: failed() runs outside handle(), with no hotel context.
        TenantContext::withoutScope(function () use ($exception) {
            $import = ReservationImport::find($this->importId);
            if (! $import || $import->isFinished()) {
                return;
            }

            Log::error('Reservation import write job failed.', ['reservation_import_id' => $import->id, 'exception' => $exception]);
            TenantContext::runForHotel($import->hotel_id, fn () => app(ReservationImportCompleter::class)->fail($import, PrepareReservationImportJob::stoppedReason($import)));
        });
    }
}
