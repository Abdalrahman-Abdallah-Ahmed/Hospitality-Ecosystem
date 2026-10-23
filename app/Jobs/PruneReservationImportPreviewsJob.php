<?php

namespace App\Jobs;

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
