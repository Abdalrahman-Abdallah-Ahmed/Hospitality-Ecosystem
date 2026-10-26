<?php

namespace App\Jobs;

use App\Enums\QueueLane;
use App\Models\ReservationImportPreview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes column-mapping uploads that were never finished (016, FR-005):
 * the file and the stored sample rows, both of which hold guest data.
 * Imports started from them keep running and keep their reports.
 *
 * A platform sweep across every hotel, so the tenant scope is lifted on
 * purpose; every step names the row it acts on.
 */
class PruneReservationImportPreviewsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // Short and not AI work: it must never wait behind a long LLM job (spec 017 FR-020).
        $this->onQueue(QueueLane::DEFAULT->value);
    }

    public function handle(): void
    {
        TenantContext::withoutScope(function () {
            $disk = Storage::disk(config('reservation_import.disk'));

            ReservationImportPreview::where('expires_at', '<=', now())
                ->chunkById(100, function ($previews) use ($disk) {
                    foreach ($previews as $preview) {
                        $disk->delete($preview->file_path);
                        $preview->delete();
                    }
                });
        });
    }
}
