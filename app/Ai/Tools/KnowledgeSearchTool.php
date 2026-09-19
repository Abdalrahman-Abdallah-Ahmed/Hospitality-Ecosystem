<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\KnowledgeChunk;
use App\Support\Knowledge\ChunkSynchronizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\SimilaritySearch;
use Stringable;

class KnowledgeSearchTool implements Tool
{
    /**
     * Candidates the HNSW index collects before the hotel filter applies
     * (pgvector's default is 40). The index is shared by every tenant, so
     * with the default a hotel's own chunks drop out as soon as 40 other
     * hotels' chunks sit closer to the query.
     */
    private const EF_SEARCH = 400;

    private static ?bool $supportsIterativeScan = null;

    private readonly SimilaritySearch $search;

    public function __construct(private readonly Hotel $hotel)
    {
        $this->search = new SimilaritySearch(function (string $query) {
            $embedding = Embeddings::for([$query])
                ->dimensions(ChunkSynchronizer::DIMENSIONS)
                ->generate()
                ->embeddings[0];

            return DB::transaction(function () use ($embedding) {
                self::widenIndexSearch();

                // The tenant scope is lifted on purpose and replaced by the
                // explicit filter below: the scope's whereIn(hotel_id) can
                // never match the shared global chunks (hotel_id is null), so
                // under any tenant context they would silently vanish.
                return KnowledgeChunk::withoutGlobalScope('hotel')
                    ->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', $this->hotel->id))
                    ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: 0.5)
                    ->limit(5)
                    ->get(['category', 'content', 'metadata', 'hotel_id']);
            });
        });

        $this->search->withDescription(
            "Search the knowledge base for information relevant to a question — covers both this hotel's own articles/policies and the shared global knowledge base."
        );
    }

    /**
     * Let a filtered search keep looking past the other hotels' chunks.
     * SET LOCAL, so it ends with the surrounding transaction. pgvector 0.8+
     * can also continue scanning the index until enough rows pass the
     * filter; older versions reject that setting, so it is only used when
     * the installed extension has it.
     */
    private static function widenIndexSearch(): void
    {
        DB::statement('set local hnsw.ef_search = '.self::EF_SEARCH);

        self::$supportsIterativeScan ??= version_compare(
            (string) DB::scalar("select extversion from pg_extension where extname = 'vector'"),
            '0.8.0',
            '>=',
        );

        if (self::$supportsIterativeScan) {
            DB::statement('set local hnsw.iterative_scan = strict_order');
        }
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
