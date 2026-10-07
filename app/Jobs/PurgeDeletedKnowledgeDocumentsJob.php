<?php

namespace App\Jobs;

use App\Models\Hotel;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes knowledge documents for good once their restore window has passed:
 * the row, its passages and its files. The audit entries stay — event_log
 * keeps the subject id with no foreign key, so the history outlives the
 * document.
 *
 * A platform sweep across every hotel, so the tenant scope is lifted on
 * purpose; every step names the row it acts on.
 */
class PurgeDeletedKnowledgeDocumentsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        TenantContext::withoutScope(function () {
            $cutoff = now()->subDays((int) config('knowledge.purge_after_days'));

            KnowledgeDocument::withoutGlobalScope('hotel')
                ->onlyTrashed()
                ->where('deleted_at', '<', $cutoff)
                ->chunkById(100, function ($documents) {
                    foreach ($documents as $document) {
                        $this->purge($document);
                    }
                });

            $this->removeOrphanedHotelDirectories();
        });
    }

    private function purge(KnowledgeDocument $document): void
    {
        $disk = $document->disk;
        $files = array_filter([$document->path, $document->pending_path]);

        DB::transaction(function () use ($document) {
            EventLogger::record($document, 'purged');

            KnowledgeChunk::withoutGlobalScope('hotel')
                ->where('chunkable_type', $document->getMorphClass())
                ->where('chunkable_id', $document->id)
                ->delete();

            $document->forceDelete();
        });

        Storage::disk($disk)->delete($files);
        Storage::disk($disk)->deleteDirectory(dirname($document->path));
    }

    /**
     * A hotel removed outright takes its document rows with it (the foreign
     * keys cascade) but not its files. Directories of hotels that no longer
     * exist are cleared here.
     */
    private function removeOrphanedHotelDirectories(): void
    {
        $disk = Storage::disk((string) config('knowledge.disk'));
        $hotelIds = collect($disk->directories('knowledge'))
            ->map(fn (string $directory) => basename($directory))
            ->reject(fn (string $name) => $name === 'global');

        if ($hotelIds->isEmpty()) {
            return;
        }

        $existing = Hotel::withTrashed()->whereKey($hotelIds->all())->pluck('id')->all();

        foreach ($hotelIds->diff($existing) as $missing) {
            $disk->deleteDirectory("knowledge/{$missing}");
        }
    }
}
