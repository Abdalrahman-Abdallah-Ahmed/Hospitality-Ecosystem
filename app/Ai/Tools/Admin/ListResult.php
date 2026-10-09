<?php

namespace App\Ai\Tools\Admin;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Ai\Tools\Request;

/**
 * The one shape every Admin AI list read returns: how many records matched,
 * how many came back, and whether that is all of them. A read never returns
 * more than MAX records (FR-007), so the model can say "showing 50 of 212"
 * instead of presenting a partial list as complete.
 */
class ListResult
{
    public const MAX = 50;

    /**
     * Count the query, take at most $limit rows, map each one, and encode.
     */
    public static function fromQuery(Builder $query, int $limit, Closure $map): string
    {
        return self::encode(self::queryPayload($query, $limit, $map));
    }

    /**
     * For lists already built in memory (report rows, service results).
     */
    public static function fromCollection(Collection $all, int $limit, ?Closure $map = null): string
    {
        return self::encode(self::collectionPayload($all, $limit, $map));
    }

    /**
     * The list as an array, for a tool that adds fields around it.
     *
     * @return array{total: int, returned: int, partial: bool, items: array<int, mixed>}
     */
    public static function queryPayload(Builder $query, int $limit, Closure $map): array
    {
        $total = (clone $query)->count();

        return self::payload($total, $query->limit($limit)->get()->map($map)->values());
    }

    /**
     * @return array{total: int, returned: int, partial: bool, items: array<int, mixed>}
     */
    public static function collectionPayload(Collection $all, int $limit, ?Closure $map = null): array
    {
        $items = $all->take($limit)->values();

        return self::payload($all->count(), $map ? $items->map($map)->values() : $items);
    }

    /**
     * The requested limit, clamped to 1..MAX.
     */
    public static function limit(Request $request, int $default = self::MAX): int
    {
        $limit = (int) ($request['limit'] ?? $default);

        return max(1, min(self::MAX, $limit));
    }

    /**
     * @return array{total: int, returned: int, partial: bool, items: array<int, mixed>}
     */
    private static function payload(int $total, Collection $items): array
    {
        return [
            'total' => $total,
            'returned' => $items->count(),
            'partial' => $items->count() < $total,
            'items' => $items->all(),
        ];
    }

    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
