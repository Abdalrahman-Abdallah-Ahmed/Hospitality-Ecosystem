<?php

namespace App\Jobs;

use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
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
    public function handle(): void
    {
        ChunkSynchronizer::sync(
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
    }
}
