<?php

namespace App\Jobs;

use App\Enums\ImportDecimalMark;
use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Enums\QueueLane;
use App\Models\Hotel;
use App\Models\ReservationImport;
use App\Support\Import\Reservations\ImportFailed;
use App\Support\Import\Reservations\ImportFileReader;
use App\Support\Import\Reservations\ImportOutcomeRecorder;
use App\Support\Import\Reservations\ImportPlan;
use App\Support\Import\Reservations\ReservationImportCompleter;
use App\Support\Import\Reservations\ReservationImportResolver;
use App\Support\Import\Reservations\ReservationMapper;
use App\Support\Import\Reservations\RowNormalizer;
use App\Support\Import\Reservations\RowValidator;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The prepare phase of a reservation import (research R1): parse →
 * normalize → validate → map → resolve over the whole file, reading the
 * database only. It records a result for every row and a draft for every
 * reservation still to be written, then hands over to
 * WriteReservationImportJob. A dry run ends here (FR-030).
 *
 * A hotel runs one import at a time, in upload order (FR-023): while an
 * older import of the same hotel is queued or running, this job releases
 * itself and waits. The write jobs of the import in progress never start
 * another import's prepare; the next one simply finds the way clear.
 */
class PrepareReservationImportJob implements ShouldQueue
{
    use Queueable;

    /** Waiting behind another import is a release, not a failure. */
    public int $tries = 0;

    public int $maxExceptions = 1;

    /** Under the database queue's retry_after, so no second worker picks it up mid-run. */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public string $importId)
    {
        // Imports run in their own lane, one at a time (spec 017 FR-020).
        $this->onQueue(QueueLane::IMPORTS->value);
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addHours(12);
    }

    public function handle(ImportFileReader $reader, RowNormalizer $normalizer, RowValidator $validator, ReservationMapper $mapper, ReservationImportResolver $resolver, ImportOutcomeRecorder $recorder, ReservationImportCompleter $completer): void
    {
        // Unscoped on purpose: found by id before its hotel is known; the work itself runs in runForHotel.
        $import = TenantContext::withoutScope(fn () => ReservationImport::find($this->importId));
        if (! $import || $import->status !== ImportStatus::QUEUED) {
            return;
        }

        if ($this->waitingBehindAnother($import)) {
            $this->release(15);

            return;
        }

        // Unscoped on purpose: the claim must see the row whatever context the worker has.
        // Claimed atomically: a redelivered copy of this job finds it running.
        $claimed = TenantContext::withoutScope(fn () => ReservationImport::whereKey($import->id)
            ->where('status', ImportStatus::QUEUED->value)
            ->update(['status' => ImportStatus::RUNNING->value, 'stage' => ImportStage::PARSE->value, 'started_at' => now()]));
        if ($claimed !== 1) {
            return;
        }

        $hasDrafts = TenantContext::runForHotel($import->hotel_id, function () use ($import, $reader, $normalizer, $validator, $mapper, $resolver, $recorder, $completer) {
            $import->refresh();

            try {
                return $this->prepare($import, $reader, $normalizer, $validator, $mapper, $resolver, $recorder);
            } catch (ImportFailed $e) {
                $completer->fail($import, $e->getMessage());
            } catch (Throwable $e) {
                Log::error('Reservation import prepare failed.', ['reservation_import_id' => $import->id, 'exception' => $e]);
                $completer->fail($import, self::stoppedReason($import->fresh()));
            }

            return false;
        });

        if ($hasDrafts === null) {
            TenantContext::runForHotel($import->hotel_id, fn () => $completer->complete($import->fresh()));
        } elseif ($hasDrafts === true) {
            WriteReservationImportJob::dispatch($import->id);
        }
    }

    /**
     * Runs the stages. Returns true when drafts wait for the write phase,
     * null when the import is complete already (a dry run, or nothing to
     * write).
     */
    private function prepare(ReservationImport $import, ImportFileReader $reader, RowNormalizer $normalizer, RowValidator $validator, ReservationMapper $mapper, ReservationImportResolver $resolver, ImportOutcomeRecorder $recorder): ?bool
    {
        $hotel = Hotel::findOrFail($import->hotel_id);
        $read = $reader->read($import->file_path, config('reservation_import.disk'), $import->header_row ?? 1, $import->column_map);
        $import->update(['total_rows' => count($read['rows']), 'unrecognized_columns' => $read['unrecognized'], 'stage' => ImportStage::NORMALIZE]);

        $decimalMark = $import->decimal_mark ?? ImportDecimalMark::AUTO;
        $rows = array_map(fn (array $row) => $validator->validate($normalizer->normalize($row['row_number'], $row['values'], $import->date_order, $hotel, $decimalMark)), $read['rows']);
        $import->update(['stage' => ImportStage::MAP]);

        $today = CarbonImmutable::now($hotel->timezone ?: 'UTC')->startOfDay();
        $plan = $resolver->resolve($mapper->map($rows, $today), $hotel, $today);

        if ($import->dry_run) {
            $this->wouldBeImported($plan);
        }

        $recorder->recordPlan($import, $plan, withDrafts: ! $import->dry_run);
        $recorder->refreshTotals($import);

        Storage::disk(config('reservation_import.disk'))->delete($import->file_path);
        $import->update([
            'file_path' => null,
            'created_rooms' => $plan->createdRooms,
            'created_room_types' => $plan->createdRoomTypes,
            'stage' => ImportStage::IMPORT,
        ]);

        return $plan->drafts === [] ? null : true;
    }

    /**
     * A dry run writes nothing: every reservation that would be written is
     * reported as imported, with no reservation behind it.
     */
    private function wouldBeImported(ImportPlan $plan): void
    {
        foreach ($plan->drafts as $draft) {
            $plan->settle($draft['rows'], ImportRowOutcome::IMPORTED, ImportStage::MAP);
        }
    }

    private function waitingBehindAnother(ReservationImport $import): bool
    {
        // Unscoped, but filtered to this import's own hotel explicitly.
        return TenantContext::withoutScope(fn () => ReservationImport::where('hotel_id', $import->hotel_id)
            ->whereKeyNot($import->id)
            ->whereIn('status', [ImportStatus::QUEUED->value, ImportStatus::RUNNING->value])
            ->where(fn ($query) => $query->where('created_at', '<', $import->created_at)
                ->orWhere(fn ($query) => $query->where('created_at', $import->created_at)->where('id', '<', $import->id)))
            ->exists());
    }

    public static function stoppedReason(?ReservationImport $import): string
    {
        $stage = $import?->stage?->value ?? 'start';

        return "The import stopped unexpectedly at the {$stage} stage. Upload the file again to finish it; rows already imported will be reported as duplicates.";
    }

    public function failed(?Throwable $exception): void
    {
        // Unscoped on purpose: failed() runs outside handle(), with no hotel context.
        TenantContext::withoutScope(function () use ($exception) {
            $import = ReservationImport::find($this->importId);
            if (! $import || $import->isFinished()) {
                return;
            }

            Log::error('Reservation import prepare job failed.', ['reservation_import_id' => $import->id, 'exception' => $exception]);
            TenantContext::runForHotel($import->hotel_id, fn () => app(ReservationImportCompleter::class)->fail($import, self::stoppedReason($import)));
        });
    }
}
