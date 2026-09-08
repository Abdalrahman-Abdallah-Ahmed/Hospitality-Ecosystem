<?php

namespace App\Jobs;

use App\Enums\ActorKind;
use App\Enums\MeterFeature;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Services\Metering\MeteringService;
use App\Support\Knowledge\ChunkSynchronizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

class SyncKnowledgeChunksJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public KnowledgeBaseArticle|HotelPolicy $chunkable,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MeteringService $metering): void
    {
        $chunks = ChunkSynchronizer::sync(
            chunkable: $this->chunkable,
            content: $this->chunkable->content,
            hotelId: $this->chunkable->hotel_id,
            category: $this->chunkable->category->value,
            metadata: [
                'title' => $this->chunkable->title,
                'tags' => $this->chunkable->tags ?? $this->chunkable->keywords,
                'version' => $this->chunkable->version,
            ],
        );

        // Embeddings are the cost everyone forgets: no visible output, real
        // money, and re-run in full every time an article is edited. One
        // event for the batch, not one per chunk.
        $metering->safely(fn (MeteringService $m) => $m->recordForHotel(
            hotel: $this->chunkable->hotel,
            feature: MeterFeature::EMBEDDINGS_GENERATED,
            quantity: $chunks,
            source: $this->chunkable,
            actorKind: ActorKind::SYSTEM,
        ));
    }
}
