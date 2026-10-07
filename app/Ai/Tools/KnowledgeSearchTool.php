<?php

namespace App\Ai\Tools;

use App\Enums\KnowledgeAudience;
use App\Enums\KnowledgeScope;
use App\Enums\KnowledgeSourceType;
use App\Models\Hotel;
use App\Models\HotelPolicy;
use App\Models\KnowledgeBaseArticle;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Knowledge\ChunkSynchronizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Searches this hotel's knowledge and the shared global knowledge, and says
 * where each passage came from.
 *
 * Hotel passages always come first: the two scopes are searched separately,
 * so a closer global passage can never push the hotel's own rule out of the
 * results. Each result is labelled `hotel` or `general`; the agent's
 * instructions say to follow the hotel source when they disagree.
 *
 * For guests, a global source is never named: its title and location are
 * left out of the result itself, so the model cannot repeat what it was
 * never given.
 */
class KnowledgeSearchTool implements Tool
{
    /**
     * Candidates the HNSW index collects before the hotel filter applies
     * (pgvector's default is 40). The index is shared by every tenant, so
     * with the default a hotel's own chunks drop out as soon as 40 other
     * hotels' chunks sit closer to the query.
     */
    private const EF_SEARCH = 400;

    /**
     * The models chunks may point at, with only the columns a citation and
     * the liveness check read (never the content or segments, which can be
     * megabytes per document). A chunk of any other type is never cited:
     * there is no source to name or to check is still live.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const SOURCE_COLUMNS = [
        KnowledgeDocument::class => ['id', 'title', 'is_active', 'updated_at', 'deleted_at'],
        KnowledgeBaseArticle::class => ['id', 'title', 'status', 'updated_at', 'deleted_at'],
        HotelPolicy::class => ['id', 'title', 'is_active', 'updated_at', 'deleted_at'],
    ];

    private static ?bool $supportsIterativeScan = null;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly KnowledgeAudience $audience = KnowledgeAudience::STAFF,
    ) {}

    public function audience(): KnowledgeAudience
    {
        return $this->audience;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search the knowledge base for information relevant to a question: this hotel\'s own documents, '
            .'articles and policies, plus the shared general knowledge base. Each result says where it came from: '
            .'"scope" is "hotel" (this hotel\'s own source, listed first) or "general" (shared guidance), with the '
            .'source\'s title, location (page, sheet or section) and last-updated date where available. Never use '
            .'it for live data such as availability, prices on a date, bookings or statuses.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $results = $this->search($request->string('query')->toString());

        if ($results === []) {
            return 'No relevant results found.';
        }

        return json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array{rank: int, scope: string, source_type: string, title: ?string, location: ?string, last_updated: ?string, content: string}>
     */
    public function search(string $query): array
    {
        $embedding = Embeddings::for([$query])
            ->dimensions(ChunkSynchronizer::DIMENSIONS)
            ->generate()
            ->embeddings[0];

        [$hotelChunks, $globalChunks] = DB::transaction(function () use ($embedding) {
            self::widenIndexSearch();

            // The tenant scope is lifted on purpose and replaced by the
            // explicit hotel_id filters below: the scope's whereIn(hotel_id)
            // can never match the shared global chunks (hotel_id is null), so
            // under any tenant context they would silently vanish.
            $similar = fn () => KnowledgeChunk::withoutGlobalScope('hotel')
                ->where('is_active', true)
                ->whereVectorSimilarTo('embedding', $embedding, minSimilarity: (float) config('knowledge.search.min_similarity'));

            return [
                $similar()->where('hotel_id', $this->hotel->id)
                    ->limit((int) config('knowledge.search.hotel_limit'))
                    ->get(['chunkable_type', 'chunkable_id', 'hotel_id', 'content', 'metadata']),
                $similar()->whereNull('hotel_id')
                    ->limit((int) config('knowledge.search.global_limit'))
                    ->get(['chunkable_type', 'chunkable_id', 'hotel_id', 'content', 'metadata']),
            ];
        });

        $chunks = $hotelChunks->concat($globalChunks);
        $sources = $this->loadSources($chunks);
        $results = [];

        foreach ($chunks as $chunk) {
            $source = $sources[$chunk->chunkable_type][$chunk->chunkable_id] ?? null;

            // Safety net behind is_active: a source that is gone, deleted,
            // unpublished or switched off never answers a question.
            if ($source === null || ! $this->isLive($source)) {
                continue;
            }

            $results[] = $this->cite($chunk, $source, count($results) + 1);
        }

        return $results;
    }

    /**
     * The sources behind the chunks, read now: an edited title is cited the
     * moment it is saved, with no re-index.
     *
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return array<string, array<string, Model>>
     */
    private function loadSources(Collection $chunks): array
    {
        $sources = [];

        foreach ($chunks->groupBy('chunkable_type') as $type => $group) {
            $columns = self::SOURCE_COLUMNS[$type] ?? null;

            if ($columns === null) {
                continue;
            }

            $sources[$type] = $type::withoutGlobalScope('hotel')
                ->withTrashed()
                ->whereKey($group->pluck('chunkable_id')->unique()->all())
                ->get($columns)
                ->keyBy(fn (Model $model) => $model->getKey())
                ->all();
        }

        return $sources;
    }

    private function isLive(Model $source): bool
    {
        if (method_exists($source, 'trashed') && $source->trashed()) {
            return false;
        }

        return match (true) {
            $source instanceof KnowledgeBaseArticle => $source->status === 'published',
            default => (bool) ($source->getAttribute('is_active') ?? true),
        };
    }

    /**
     * @return array{rank: int, scope: string, source_type: string, title: ?string, location: ?string, last_updated: ?string, content: string}
     */
    private function cite(KnowledgeChunk $chunk, Model $source, int $rank): array
    {
        $scope = $chunk->hotel_id === null ? KnowledgeScope::GENERAL : KnowledgeScope::HOTEL;
        $hideSource = $scope === KnowledgeScope::GENERAL && $this->audience === KnowledgeAudience::GUEST;

        return [
            'rank' => $rank,
            'scope' => $scope->value,
            'source_type' => $hideSource ? 'general' : KnowledgeSourceType::fromModel($source)->value,
            'title' => $hideSource ? null : $source->getAttribute('title'),
            'location' => $hideSource ? null : ($chunk->metadata['location'] ?? null),
            'last_updated' => $source->getAttribute('updated_at')?->toDateString(),
            'content' => $chunk->content,
        ];
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
}
