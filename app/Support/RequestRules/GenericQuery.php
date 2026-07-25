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
        $query->filter((array) $request->input('filter', []))
            ->search($request->string('search')->toString() ?: null);

        if ($sort = $request->string('sort')->toString()) {
            $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        }

        return $query->paginate($request->integer('per_page', 15));
    }
}
