<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\KnowledgeChunk;
use App\Support\Knowledge\ChunkSynchronizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;
use Stringable;

class KnowledgeSearchTool implements Tool
{
    private readonly SimilaritySearch $search;

    public function __construct(private readonly Hotel $hotel)
    {
        $this->search = new SimilaritySearch(function (string $query) {
            $embedding = Embeddings::for([$query])
                ->dimensions(ChunkSynchronizer::DIMENSIONS)
                ->generate()
                ->embeddings[0];

            return KnowledgeChunk::query()
                ->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $this->hotel->id))
                ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.5)
                ->limit(5)
                ->get(['category', 'content', 'metadata', 'hotel_id']);
        });

        $this->search->withDescription(
            "Search the knowledge base for information relevant to a question — covers both this hotel's own articles/policies and the shared global knowledge base."
        );
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->search->description();
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->search->handle($request);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->search->schema($schema);
    }
}
