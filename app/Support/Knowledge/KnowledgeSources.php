<?php

namespace App\Support\Knowledge;

use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Database\Eloquent\Model;

/**
 * The records knowledge passages come from — documents, articles and
 * policies — and the one rule for whether such a record may still be used.
 *
 * Search, indexing and insight citations all ask the same question, so they
 * ask it here: a fourth source type or a change to what "live" means is made
 * once.
 */
final class KnowledgeSources
{
    /**
     * Whether a source may answer a question or be opened from a citation:
     * not deleted, published (articles) or switched on (everything else).
     */
    public static function isLive(Model $source): bool
    {
        if (method_exists($source, 'trashed') && $source->trashed()) {
            return false;
        }

        return $source instanceof KnowledgeBaseArticle
            ? $source->status === 'published'
            : (bool) ($source->getAttribute('is_active') ?? true);
    }

    /**
     * Which cited sources are live right now, in one query per source type.
     *
     * @param  iterable<array{source_type?: string, source_id?: string}>  $citations
     * @return array<string, bool> key() => live; a source that is gone is absent
     */
    public static function availability(iterable $citations): array
    {
        $live = [];

        foreach (self::idsByType($citations) as $type => $ids) {
            $model = KnowledgeSourceType::from($type)->modelClass();

            $model::withoutGlobalScope('hotel')->withTrashed()->whereKey($ids)->get()
                ->each(function (Model $source) use (&$live, $type) {
                    $live[self::key($type, (string) $source->getKey())] = self::isLive($source);
                });
        }

        return $live;
    }

    /**
     * Which cited sources still exist (not deleted) and belong to this hotel
     * or to the shared global knowledge, in one query per source type.
     *
     * @param  iterable<array{source_type?: string, source_id?: string}>  $citations
     * @return array<string, true> key() => true
     */
    public static function existing(iterable $citations, string $hotelId): array
    {
        $existing = [];

        foreach (self::idsByType($citations) as $type => $ids) {
            KnowledgeSourceType::from($type)->modelClass()::withoutGlobalScope('hotel')
                ->whereKey($ids)
                ->where(fn ($query) => $query->where('hotel_id', $hotelId)->orWhereNull('hotel_id'))
                ->pluck('id')
                ->each(function ($id) use (&$existing, $type) {
                    $existing[self::key($type, (string) $id)] = true;
                });
        }

        return $existing;
    }

    public static function key(string $sourceType, string $sourceId): string
    {
        return $sourceType.'|'.$sourceId;
    }

    /**
     * @param  iterable<array{source_type?: string, source_id?: string}>  $citations
     * @return array<string, list<string>>
     */
    private static function idsByType(iterable $citations): array
    {
        $ids = [];

        foreach ($citations as $citation) {
            $type = KnowledgeSourceType::tryFrom((string) ($citation['source_type'] ?? ''));

            if ($type !== null && isset($citation['source_id'])) {
                $ids[$type->value][(string) $citation['source_id']] = (string) $citation['source_id'];
            }
        }

        return array_map('array_values', $ids);
    }
}
