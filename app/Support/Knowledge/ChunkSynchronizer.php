<?php

namespace App\Support\Knowledge;

use App\Models\KnowledgeChunk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;

/**
 * Regenerates the searchable, embedded knowledge_chunks rows for a single
 * source model (an article, policy, or document). This is the one place
 * chunking + embedding + storage happens — every source type calls into it
 * instead of duplicating that logic.
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
     * @param  array<string, mixed>  $metadata
     */
    /**
     * Returns the number of chunks embedded, which is what the embedding call
     * actually cost — the caller meters on it.
     */
    public static function sync(
        Model $chunkable,
        string $content,
        ?string $hotelId,
        ?string $category,
        array $metadata = []
    ): int {
        KnowledgeChunk::query()
            ->where('chunkable_type', $chunkable->getMorphClass())
            ->where('chunkable_id', $chunkable->getKey())
            ->delete();

        $chunks = TextChunker::chunk($content);

        if ($chunks === []) {
            return 0;
        }

        $embeddings = Embeddings::for($chunks)->dimensions(self::DIMENSIONS)->generate()->embeddings;

        $now = now();

        $rows = [];

        foreach ($chunks as $index => $chunkContent) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'chunkable_type' => $chunkable->getMorphClass(),
                'chunkable_id' => $chunkable->getKey(),
                'hotel_id' => $hotelId,
                'category' => $category,
                'chunk_index' => $index,
                'content' => $chunkContent,
                'token_count' => TextChunker::estimateTokens($chunkContent),
                'metadata' => json_encode($metadata),
                'embedding' => json_encode($embeddings[$index]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        KnowledgeChunk::insert($rows);

        return count($rows);
    }
}
