<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeRebuildScope;
use App\Enums\KnowledgeRebuildStatus;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Jobs\SyncKnowledgeChunksJob;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeIndexRebuild;
use App\Models\User;
use App\Support\Audit\EventLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Re-indexes knowledge in bulk: everything, one hotel, or the global sources
 * only. Documents are rebuilt from their stored text (staff corrections kept,
 * no extraction, no AI vision); a document without stored text is extracted
 * from its file. Articles and policies are re-synced.
 *
 * Every source swaps its chunks atomically, so search keeps answering from
 * the previous passages until each new set is in, and a source that fails
 * keeps the passages it had.
 */
class KnowledgeRebuildService
{
    public function start(KnowledgeRebuildScope $scope, ?Hotel $hotel, ?User $actor, bool $includeDocuments = true): KnowledgeIndexRebuild
    {
        return TenantContext::withoutScope(function () use ($scope, $hotel, $actor, $includeDocuments) {
            // Only whether each document has stored text, never the text
            // itself: a rebuild of everything must not load it all at once.
            $documents = $includeDocuments
                ? $this->inScope(KnowledgeDocument::withoutGlobalScope('hotel')->where('is_active', true), $scope, $hotel)
                    ->toBase()
                    ->selectRaw('id, (segments IS NOT NULL) AS has_segments')
                    ->get()
                : collect();
            $articles = $this->inScope(KnowledgeBaseArticle::withoutGlobalScope('hotel')->where('status', 'published'), $scope, $hotel)->get();
            $policies = $scope === KnowledgeRebuildScope::GLOBAL
                ? collect()
                : $this->inScope(HotelPolicy::withoutGlobalScope('hotel')->where('is_active', true), $scope, $hotel)->get();

            $total = $documents->count() + $articles->count() + $policies->count();

            return DB::transaction(function () use ($scope, $hotel, $actor, $documents, $articles, $policies, $total) {
                $rebuild = KnowledgeIndexRebuild::create([
                    'scope' => $scope,
                    'hotel_id' => $scope === KnowledgeRebuildScope::HOTEL ? $hotel?->id : null,
                    'requested_by' => $actor?->id,
                    'status' => $total === 0 ? KnowledgeRebuildStatus::COMPLETED : KnowledgeRebuildStatus::RUNNING,
                    'total' => $total,
                    'started_at' => now(),
                    'finished_at' => $total === 0 ? now() : null,
                ]);

                EventLogger::record($rebuild, 'started', ['total' => ['to' => $total]]);

                foreach ($documents as $document) {
                    $mode = $document->has_segments
                        ? IndexKnowledgeDocumentJob::MODE_FROM_SEGMENTS
                        : IndexKnowledgeDocumentJob::MODE_EXTRACT;

                    IndexKnowledgeDocumentJob::dispatch($document->id, $mode, $rebuild->id)->afterCommit();
                }

                foreach ([...$articles, ...$policies] as $source) {
                    SyncKnowledgeChunksJob::dispatch($source, $rebuild->id)->afterCommit();
                }

                return $rebuild;
            });
        });
    }

    private function inScope(Builder $query, KnowledgeRebuildScope $scope, ?Hotel $hotel): Builder
    {
        return match ($scope) {
            KnowledgeRebuildScope::ALL => $query,
            KnowledgeRebuildScope::GLOBAL => $query->whereNull('hotel_id'),
            KnowledgeRebuildScope::HOTEL => $query->where('hotel_id', $hotel?->id),
        };
    }
}
