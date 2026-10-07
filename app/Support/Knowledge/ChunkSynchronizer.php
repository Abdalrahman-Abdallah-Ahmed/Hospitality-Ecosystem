<?php

namespace App\Support\Knowledge;

use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeChunk;
use App\Support\Knowledge\Extraction\Segment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * Regenerates the searchable, embedded knowledge_chunks rows for a single
 * source model (an article, policy, or document). This is the one place
 * chunking + embedding + storage happens — every source type calls into it
 * instead of duplicating that logic.
 *
 * The swap is all-or-nothing: everything is embedded first, outside any
 * transaction, and only then are the old chunks replaced in one transaction.
 * A search never sees a half-indexed source, and an embedding failure leaves
 * the previous chunks exactly where they were.
 */
class ChunkSynchronizer
{
    /**
     * Pinned rather than left to each provider's default, since providers
     * disagree on default output size (OpenAI: 1536, Gemini: 3072) and the
     * embedding column width is fixed at migration time.
     */
    public const DIMENSIONS = 1536;

    /**
     * Returns the number of chunks embedded, which is what the embedding call
     * actually cost — the caller meters on it.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function sync(
        Model $chunkable,
        string $content,
        ?string $hotelId,
        ?string $category,
        array $metadata = []
    ): int {
        return self::syncSegments(
            source: $chunkable,
            segments: [new Segment(null, $content)],
            hotelId: $hotelId,
            category: $category,
            metadata: $metadata,
        ) ?? 0;
    }

    /**
     * Chunk each segment on its own, so every chunk carries exactly one
     * location, then swap the source's chunks atomically.
     *
     * $guard runs on the locked source inside the swap transaction; returning
     * false means the run is stale (the source changed or was deleted while
     * embedding) and nothing is written. $afterSwap runs in the same
     * transaction, for the caller's own bookkeeping.
     *
     * Returns the chunk count, or null when the guard refused the swap.
     *
     * @param  list<Segment>  $segments
     * @param  array<string, mixed>  $metadata
     * @param  (Closure(Model): bool)|null  $guard
     * @param  (Closure(Model, int): void)|null  $afterSwap
     */
    public static function syncSegments(
        Model $source,
        array $segments,
        ?string $hotelId,
        ?string $category,
        array $metadata = [],
        ?Closure $guard = null,
        ?Closure $afterSwap = null,
    ): ?int {
        $pieces = [];

        foreach ($segments as $segment) {
            foreach (TextChunker::chunk($segment->text) as $content) {
                $pieces[] = ['location' => $segment->location, 'content' => $content];
            }
        }

        $embeddings = $pieces === []
            ? []
            : Embeddings::for(array_column($pieces, 'content'))->dimensions(self::DIMENSIONS)->generate()->embeddings;

        $sourceType = KnowledgeSourceType::fromModel($source)->value;

        return DB::transaction(function () use ($source, $pieces, $embeddings, $hotelId, $category, $metadata, $sourceType, $guard, $afterSwap) {
            $locked = $source->newQueryWithoutScopes()
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ($guard !== null && ! $guard($locked))) {
                return null;
            }

            KnowledgeChunk::query()
                ->withoutGlobalScope('hotel')
                ->where('chunkable_type', $source->getMorphClass())
                ->where('chunkable_id', $source->getKey())
                ->delete();

            // Read from the locked row, never from when the run started: a
            // source deactivated while it was being embedded must come back
            // inactive. Articles have no such column and are always active.
            $active = (bool) ($locked->getAttribute('is_active') ?? true);

            $now = now();
            $rows = [];

            foreach ($pieces as $index => $piece) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'chunkable_type' => $source->getMorphClass(),
                    'chunkable_id' => $source->getKey(),
                    'hotel_id' => $hotelId,
                    'category' => $category,
                    'chunk_index' => $index,
                    'content' => $piece['content'],
                    'token_count' => TextChunker::estimateTokens($piece['content']),
                    'metadata' => json_encode([
                        ...$metadata,
                        'source_type' => $sourceType,
                        'location' => $piece['location'],
                    ]),
                    'embedding' => json_encode($embeddings[$index]),
                    'is_active' => $active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $batch) {
                KnowledgeChunk::insert($batch);
            }

            if ($afterSwap !== null) {
                $afterSwap($locked, count($rows));
            }

            return count($rows);
        });
    }

    /**
     * Switch a source's chunks on or off for search without re-embedding.
     */
    public static function setActive(Model $source, bool $active): void
    {
        KnowledgeChunk::query()
            ->withoutGlobalScope('hotel')
            ->where('chunkable_type', $source->getMorphClass())
            ->where('chunkable_id', $source->getKey())
            ->update(['is_active' => $active, 'updated_at' => now()]);
    }
}
