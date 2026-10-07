<?php

namespace App\Jobs;

use App\Enums\ActorKind;
use App\Enums\AiTriggerKind;
use App\Enums\MeterFeature;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeIndexRebuild;
use App\Services\Metering\MeteringService;
use App\Support\Ai\AiCostContext;
use App\Support\Knowledge\ChunkSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Re-embeds one article or policy.
 *
 * Holds the source's class and id, not the model itself: a serialized model
 * that no longer exists (deleted, or cascaded away with its hotel) would fail
 * the job before it runs, so a rebuild waiting on it would never complete.
 */
class SyncKnowledgeChunksJob implements ShouldQueue
{
    use Queueable;

    /** @var class-string<KnowledgeBaseArticle|HotelPolicy> */
    public string $chunkableType;

    public string $chunkableId;

    public function __construct(
        KnowledgeBaseArticle|HotelPolicy $chunkable,
        public ?string $rebuildId = null,
    ) {
        $this->chunkableType = $chunkable::class;
        $this->chunkableId = $chunkable->getKey();
    }

    /**
     * Execute the job.
     */
    public function handle(MeteringService $metering): void
    {
        $chunkable = $this->chunkableType::withoutGlobalScope('hotel')->find($this->chunkableId);

        // Gone, unpublished or switched off since it was queued: its observer
        // already removed its chunks, and re-creating them would put it back
        // into search.
        if ($chunkable === null || ! $this->isLive($chunkable)) {
            $this->reportRebuild(true, $chunkable);

            return;
        }

        // Embeddings are pure cost with no visible output, and the easiest
        // line to forget entirely. The context makes them attributable: they
        // are our own work, not something a guest asked for.
        $chunks = AiCostContext::for(
            kind: AiTriggerKind::SCHEDULED_JOB,
            hotel: $chunkable->hotel,
            trigger: $chunkable,
            callback: fn () => ChunkSynchronizer::sync(
                chunkable: $chunkable,
                content: $chunkable->content,
                hotelId: $chunkable->hotel_id,
                category: $chunkable->category?->value,
                metadata: [
                    'title' => $chunkable->title,
                    'tags' => $chunkable->tags ?? $chunkable->keywords,
                    'version' => $chunkable->version,
                ],
            ),
        );

        // Embeddings are the cost everyone forgets: no visible output, real
        // money, and re-run in full every time an article is edited. One
        // event for the batch, not one per chunk. Global articles have no
        // account to bill; their cost stays in the usage log as platform cost.
        if ($chunkable->hotel !== null) {
            $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
                hotel: $chunkable->hotel,
                feature: MeterFeature::EMBEDDINGS_GENERATED,
                quantity: $chunks,
                source: $chunkable,
                actorKind: ActorKind::SYSTEM,
            ));
        }

        $this->reportRebuild(true, $chunkable);
    }

    /**
     * The sync swaps atomically, so a failure here leaves the source's
     * previous chunks searchable.
     */
    public function failed(?Throwable $exception): void
    {
        $chunkable = $this->chunkableType::withoutGlobalScope('hotel')->withTrashed()->find($this->chunkableId);

        $this->reportRebuild(false, $chunkable, $exception?->getMessage());
    }

    private function isLive(KnowledgeBaseArticle|HotelPolicy $chunkable): bool
    {
        return $chunkable instanceof KnowledgeBaseArticle
            ? $chunkable->status === 'published'
            : (bool) $chunkable->is_active;
    }

    private function reportRebuild(bool $succeeded, KnowledgeBaseArticle|HotelPolicy|null $chunkable, ?string $reason = null): void
    {
        if ($this->rebuildId === null) {
            return;
        }

        KnowledgeIndexRebuild::find($this->rebuildId)?->recordOutcome($succeeded, $succeeded ? null : [
            'source_type' => $this->chunkableType === HotelPolicy::class ? 'policy' : 'article',
            'source_id' => $this->chunkableId,
            'title' => $chunkable?->title,
            'reason' => mb_substr($reason ?? 'failed', 0, 200),
        ]);
    }
}
