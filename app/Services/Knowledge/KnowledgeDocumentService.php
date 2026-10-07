<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeContentSource;
use App\Enums\KnowledgeDocumentStatus;
use App\Exceptions\DuplicateKnowledgeDocumentException;
use App\Exceptions\KnowledgeDocumentException;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\Hotel;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\Audit\EventLogger;
use App\Support\Knowledge\ChunkSynchronizer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything staff can do to a knowledge document. The hotel controller and
 * the super-admin (global) controller both delegate here; `$hotel = null`
 * means the global scope throughout.
 *
 * Rules every method keeps:
 *  - a stored file is never written to, moved or deleted, except when a
 *    replacement's swap commits (the old file), a pending replacement is
 *    superseded, or the purge job removes a long-deleted document;
 *  - anything that changes what the index is built from dispatches an
 *    indexing run after commit; the live passages stay until it swaps;
 *  - activation and deletion flip the passages in the same transaction, so
 *    search reflects them within the request.
 */
class KnowledgeDocumentService
{
    public function upload(UploadedFile $file, string $mimeType, array $data, ?Hotel $hotel, ?User $actor): KnowledgeDocument
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $this->assertNotDuplicate($hotel, $hash);

        $disk = (string) config('knowledge.disk');
        $id = (string) Str::uuid();
        $path = $this->storeFile($file, $hotel, $id);

        try {
            $document = $this->withoutHotelStamp(fn () => DB::transaction(fn () => tap((new KnowledgeDocument)->forceFill([
                // The file is already stored under this id's directory.
                'id' => $id,
                'hotel_id' => $hotel?->id,
                'uploaded_by' => $actor?->id,
                'title' => $data['title'],
                'category' => $data['category'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'original_filename' => $file->getClientOriginalName(),
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => $file->getSize(),
                'content_hash' => $hash,
                'status' => KnowledgeDocumentStatus::UPLOADED,
            ]))->save()));
        } catch (UniqueConstraintViolationException $e) {
            // Two identical uploads raced past the check above: answer the
            // loser with the same 409 as a plain duplicate.
            Storage::disk($disk)->delete($path);
            $this->assertNotDuplicate($hotel, $hash);

            throw $e;
        }

        $this->dispatchIndex($document, IndexKnowledgeDocumentJob::MODE_EXTRACT);

        return $document;
    }

    /**
     * Title and category change what citations say, not what is indexed, so
     * neither re-embeds anything. `is_active` flips the passages here.
     */
    public function update(KnowledgeDocument $document, array $data): KnowledgeDocument
    {
        return DB::transaction(function () use ($document, $data) {
            $wasActive = $document->is_active;

            $document->fill(array_intersect_key($data, array_flip(['title', 'category', 'is_active'])));
            $categoryChanged = $document->isDirty('category');
            $document->save();

            if ($categoryChanged) {
                $this->chunksOf($document)->update(['category' => $document->category?->value, 'updated_at' => now()]);
            }

            if (array_key_exists('is_active', $data) && $wasActive !== $document->is_active) {
                if (! $document->trashed()) {
                    ChunkSynchronizer::setActive($document, $document->is_active);
                }

                EventLogger::record($document, $document->is_active ? 'activated' : 'deactivated');
            }

            return $document->refresh();
        });
    }

    /**
     * Store the replacement beside the current file. The current version and
     * its passages stay live until the replacement's indexing run swaps in.
     */
    public function replaceFile(KnowledgeDocument $document, UploadedFile $file, string $mimeType): KnowledgeDocument
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $hotel = $document->hotel_id ? Hotel::find($document->hotel_id) : null;
        $this->assertNotDuplicate($hotel, $hash, except: $document);

        $path = $this->storeFile($file, $hotel, $document->id);
        $previousPending = $document->pending_path;

        DB::transaction(function () use ($document, $file, $mimeType, $hash, $path) {
            $document->forceFill([
                'pending_path' => $path,
                'pending_original_filename' => $file->getClientOriginalName(),
                'pending_mime_type' => $mimeType,
                'pending_size' => $file->getSize(),
                'pending_content_hash' => $hash,
                'status' => KnowledgeDocumentStatus::UPLOADED,
                'failure_code' => null,
                'error' => null,
            ])->save();

            EventLogger::record($document, 'replacement_uploaded', [
                'original_filename' => ['to' => $file->getClientOriginalName()],
            ]);
        });

        if ($previousPending !== null && $previousPending !== $path) {
            DB::afterCommit(fn () => Storage::disk($document->disk)->delete($previousPending));
        }

        $this->dispatchIndex($document, IndexKnowledgeDocumentJob::MODE_EXTRACT);

        return $document->refresh();
    }

    public function reindex(KnowledgeDocument $document): KnowledgeDocument
    {
        $mode = $document->segments !== null && ! $document->hasPendingReplacement()
            ? IndexKnowledgeDocumentJob::MODE_FROM_SEGMENTS
            : IndexKnowledgeDocumentJob::MODE_EXTRACT;

        EventLogger::record($document, 'reindex_requested', ['mode' => ['to' => $mode]]);
        $this->dispatchIndex($document, $mode);

        return $document->refresh();
    }

    public function delete(KnowledgeDocument $document): void
    {
        DB::transaction(function () use ($document) {
            $document->delete();
            ChunkSynchronizer::setActive($document, false);
        });
    }

    public function restore(KnowledgeDocument $document): KnowledgeDocument
    {
        if (! $document->trashed() || $document->deleted_at->lt(now()->subDays((int) config('knowledge.purge_after_days')))) {
            abort(404);
        }

        // The same file may have been uploaded again while this one was
        // deleted; only one live copy per scope is allowed.
        $this->assertNotDuplicate($document->hotel_id ? Hotel::find($document->hotel_id) : null, (string) $document->content_hash, except: $document);

        return DB::transaction(function () use ($document) {
            $document->restore();
            ChunkSynchronizer::setActive($document, $document->is_active);
            EventLogger::record($document, 'restored');

            return $document->refresh();
        });
    }

    /**
     * Staff edits per segment. Locations are fixed, so every citation still
     * points at the right page; an emptied segment drops out of the index.
     *
     * @param  list<array{location: ?string, text: ?string}>  $segments
     */
    public function correctText(KnowledgeDocument $document, array $segments, ?User $actor): KnowledgeDocument
    {
        $stored = $document->segments;

        if ($stored === null || $stored === []) {
            throw KnowledgeDocumentException::because('segments', 'This document has no extracted text to correct yet.');
        }

        $storedLocations = array_map(fn (array $segment) => $segment['location'] ?? null, $stored);
        $givenLocations = array_map(fn (array $segment) => $segment['location'] ?? null, $segments);

        if ($storedLocations !== $givenLocations) {
            throw KnowledgeDocumentException::because('segments', 'The corrected text must keep the same locations, in the same order.');
        }

        $corrected = array_map(fn (array $segment) => [
            'location' => $segment['location'] ?? null,
            'text' => trim((string) ($segment['text'] ?? '')),
        ], $segments);

        DB::transaction(function () use ($document, $corrected, $actor) {
            $document->forceFill([
                'segments' => $corrected,
                'content' => self::joinSegments($corrected),
                'content_source' => KnowledgeContentSource::CORRECTED,
                'corrected_by' => $actor?->id,
                'corrected_at' => now(),
            ])->save();

            EventLogger::record($document, 'text_corrected');
        });

        $this->dispatchIndex($document, IndexKnowledgeDocumentJob::MODE_FROM_SEGMENTS);

        return $document->refresh();
    }

    public function discardCorrections(KnowledgeDocument $document): KnowledgeDocument
    {
        DB::transaction(function () use ($document) {
            $document->forceFill([
                'content_source' => KnowledgeContentSource::EXTRACTED,
                'corrected_by' => null,
                'corrected_at' => null,
            ])->save();

            EventLogger::record($document, 'text_correction_discarded');
        });

        $this->dispatchIndex($document, IndexKnowledgeDocumentJob::MODE_EXTRACT);

        return $document->refresh();
    }

    /**
     * One document in exactly one scope: a hotel's own, or the global ones.
     * Anything else is a 404, never a 403, so no existence leaks across.
     */
    public function findForScope(?Hotel $hotel, string $id, bool $withTrashed = false): KnowledgeDocument
    {
        return $this->scopeQuery($hotel, $withTrashed)->whereKey($id)->firstOrFail();
    }

    public function scopeQuery(?Hotel $hotel, bool $withTrashed = false): Builder
    {
        $query = KnowledgeDocument::withoutGlobalScope('hotel');

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $hotel === null
            ? $query->whereNull('hotel_id')
            : $query->where('hotel_id', $hotel->id);
    }

    public function deletedQuery(?Hotel $hotel): Builder
    {
        return $this->scopeQuery($hotel)
            ->onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays((int) config('knowledge.purge_after_days')));
    }

    public function dispatchIndex(KnowledgeDocument $document, string $mode, ?string $rebuildId = null): void
    {
        IndexKnowledgeDocumentJob::dispatch($document->id, $mode, $rebuildId)->afterCommit();
    }

    /**
     * @param  list<array{location: ?string, text: string}>  $segments
     */
    public static function joinSegments(array $segments): string
    {
        return collect($segments)
            ->filter(fn (array $segment) => trim($segment['text'] ?? '') !== '')
            ->map(fn (array $segment) => trim(($segment['location'] ?? '')."\n".$segment['text']))
            ->implode("\n\n");
    }

    private function assertNotDuplicate(?Hotel $hotel, string $hash, ?KnowledgeDocument $except = null): void
    {
        $existing = $this->scopeQuery($hotel)
            ->where(fn (Builder $q) => $q->where('content_hash', $hash)->orWhere('pending_content_hash', $hash))
            ->when($except, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->first();

        if ($existing !== null) {
            throw new DuplicateKnowledgeDocumentException($existing);
        }
    }

    private function storeFile(UploadedFile $file, ?Hotel $hotel, string $documentId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $directory = 'knowledge/'.($hotel?->id ?? 'global').'/'.$documentId;

        return $file->storeAs($directory, Str::uuid().'.'.$extension, ['disk' => config('knowledge.disk')]);
    }

    private function chunksOf(KnowledgeDocument $document): Builder
    {
        return KnowledgeChunk::query()
            ->withoutGlobalScope('hotel')
            ->where('chunkable_type', $document->getMorphClass())
            ->where('chunkable_id', $document->id);
    }

    /**
     * BelongsToHotel stamps the current hotel on create when hotel_id is
     * empty. A global document must stay global, whatever context is set.
     */
    private function withoutHotelStamp(callable $callback): mixed
    {
        $previous = TenantContext::currentHotelId();
        TenantContext::setCurrentHotelId(null);

        try {
            return $callback();
        } finally {
            TenantContext::setCurrentHotelId($previous);
        }
    }
}
