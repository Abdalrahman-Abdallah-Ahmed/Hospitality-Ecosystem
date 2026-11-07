<?php

namespace App\Support\RequestRules;

use App\Http\Requests\Generic\GenericIndexRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the column filters, sort, and pagination validated by a
 * GenericIndexRequest onto a query builder.
 */
class GenericQuery
{
    public static function apply(Builder $query, GenericIndexRequest $request): LengthAwarePaginator
    {
        return self::paginate(self::query($query, $request), $request);
    }

    /**
     * One page of an already filtered query, at the requested page size.
     */
    public static function paginate(Builder $query, GenericIndexRequest $request): LengthAwarePaginator
    {
        return $query->paginate($request->integer('per_page', 15));
    }

    /**
     * The filters, search and sort without the pagination: what a list
     * export reads, so it selects exactly what the list shows (spec 021).
     */
    public static function query(Builder $query, GenericIndexRequest $request): Builder
    {
        $term = $request->string('search')->toString() ?: null;
        $columns = $request->searchColumns();

        $query->filter((array) $request->input('filter', []));

        $columns === null ? $query->search($term) : $query->searchColumns($term, $columns);

        if ($sort = $request->string('sort')->toString()) {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        return $query;
    }
}
