<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Generic ?filter[column]=value and ?search=term query scopes usable on
 * any model, driven entirely by the model's own table columns so no
 * per-model filter/search list has to be hand written.
 */
trait Filterable
{
    /**
     * Exact-match (or whereIn, for array values) filtering restricted to
     * columns that actually exist on the model's table.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $columns = static::filterableColumns($query);

        foreach ($filters as $column => $value) {
            if (! in_array($column, $columns, true) || $value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        return $query;
    }

    /**
     * Free-text search across the model's string/text columns (or the
     * model's own $searchable list, when it defines one).
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $columns = static::searchableColumns($query);

        if ($columns === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($columns, $term) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    protected static function filterableColumns(Builder $query): array
    {
        return Schema::getColumnListing($query->getModel()->getTable());
    }

    protected static function searchableColumns(Builder $query): array
    {
        $model = $query->getModel();

        if (property_exists($model, 'searchable')) {
            return $model->searchable;
        }

        return collect(Schema::getColumns($model->getTable()))
            ->filter(function (array $column) {
                $typeName = strtolower($column['type_name'] ?? '');

                return str_contains($typeName, 'char') || str_contains($typeName, 'text');
            })
            ->pluck('name')
            ->reject(fn ($name) => in_array($name, [$model->getKeyName(), 'password', 'remember_token'], true))
            ->values()
            ->all();
    }
}
