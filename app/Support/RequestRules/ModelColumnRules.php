<?php

namespace App\Support\RequestRules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Builds FormRequest validation rules for a model straight from its
 * database schema (column types, nullability, defaults, unique indexes,
 * foreign keys) and its Eloquent casts, so no per-model rule list has
 * to be hand written for plain CRUD endpoints.
 */
class ModelColumnRules
{
    public static function forCreate(string $modelClass): array
    {
        return static::build($modelClass, forUpdate: false);
    }

    public static function forUpdate(string $modelClass, ?Model $ignore = null): array
    {
        return static::build($modelClass, forUpdate: true, ignore: $ignore);
    }

    public static function fillable(string $modelClass): array
    {
        $model = new $modelClass;
        $fillable = $model->getFillable();

        if ($fillable !== []) {
            return $fillable;
        }

        return array_values(array_diff(
            Schema::getColumnListing($model->getTable()),
            static::systemColumns($model)
        ));
    }

    protected static function build(string $modelClass, bool $forUpdate, ?Model $ignore = null): array
    {
        $model = new $modelClass;
        $table = $model->getTable();

        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $uniqueColumns = static::uniqueColumns($table);
        $foreignKeys = static::foreignKeys($table);
        $casts = $model->getCasts();

        $rules = [];

        foreach (static::fillable($modelClass) as $column) {
            $meta = $columns->get($column);

            if (! $meta) {
                continue;
            }

            $rules[$column] = static::rulesForColumn(
                column: $column,
                meta: $meta,
                forUpdate: $forUpdate,
                cast: $casts[$column] ?? null,
                isUnique: $uniqueColumns[$column] ?? false,
                foreignKey: $foreignKeys[$column] ?? null,
                table: $table,
                ignore: $ignore,
            );
        }

        return $rules;
    }

    protected static function rulesForColumn(
        string $column,
        array $meta,
        bool $forUpdate,
        ?string $cast,
        bool $isUnique,
        ?array $foreignKey,
        string $table,
        ?Model $ignore,
    ): array {
        $nullable = (bool) ($meta['nullable'] ?? false);
        $hasDefault = ($meta['default'] ?? null) !== null;

        $rules = [];

        if ($forUpdate) {
            $rules[] = 'sometimes';
            if ($nullable) {
                $rules[] = 'nullable';
            }
        } else {
            $rules[] = ($nullable || $hasDefault) ? 'nullable' : 'required';
        }

        array_push($rules, ...static::typeRules($meta, $cast));

        if ($foreignKey) {
            $rules[] = Rule::exists($foreignKey['table'], $foreignKey['column']);
        }

        if ($isUnique) {
            $unique = Rule::unique($table, $column);

            if ($ignore) {
                $unique = $unique->ignore($ignore->getKey(), $ignore->getKeyName());
            }

            $rules[] = $unique;
        }

        return $rules;
    }

    protected static function typeRules(array $meta, ?string $cast): array
    {
        if ($cast !== null && enum_exists($cast)) {
            return [Rule::enum($cast)];
        }

        switch (true) {
            case in_array($cast, ['boolean', 'bool'], true):
                return ['boolean'];

            case in_array($cast, ['integer', 'int'], true):
                return ['integer'];

            case $cast !== null && str_starts_with($cast, 'decimal'):
            case in_array($cast, ['float', 'double', 'real'], true):
                return ['numeric'];

            case in_array($cast, ['array', 'json', 'collection', 'object'], true):
            case $cast !== null && (str_starts_with($cast, 'AsArrayObject') || str_starts_with($cast, 'AsCollection')):
                return ['array'];

            case in_array($cast, ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp'], true):
                return ['date'];
        }

        return static::rulesFromDbType($meta);
    }

    protected static function rulesFromDbType(array $meta): array
    {
        $type = strtolower($meta['type'] ?? '');
        $typeName = strtolower($meta['type_name'] ?? '');

        if ($type === 'tinyint(1)' || in_array($typeName, ['boolean', 'bool'], true)) {
            return ['boolean'];
        }

        if (str_starts_with($typeName, 'enum') && preg_match('/\(([^)]+)\)/', $meta['type'] ?? '', $matches)) {
            $values = array_map(fn ($value) => trim($value, " '\""), explode(',', $matches[1]));

            return [Rule::in($values)];
        }

        if (str_contains($typeName, 'int')) {
            return ['integer'];
        }

        if (preg_match('/decimal|numeric|double|float|real/', $typeName)) {
            return ['numeric'];
        }

        if (in_array($typeName, ['json', 'jsonb'], true)) {
            return ['array'];
        }

        if ($typeName === 'uuid') {
            return ['uuid'];
        }

        if (in_array($typeName, ['date', 'datetime', 'timestamp'], true)) {
            return ['date'];
        }

        if (str_contains($typeName, 'char') || str_contains($typeName, 'text')) {
            $rules = ['string'];

            if (preg_match('/\((\d+)\)/', $type, $matches)) {
                $rules[] = 'max:'.$matches[1];
            }

            return $rules;
        }

        return ['string'];
    }

    protected static function uniqueColumns(string $table): array
    {
        return collect(Schema::getIndexes($table))
            ->filter(fn ($index) => $index['unique'] && ! $index['primary'] && count($index['columns']) === 1)
            ->mapWithKeys(fn ($index) => [$index['columns'][0] => true])
            ->all();
    }

    protected static function foreignKeys(string $table): array
    {
        return collect(Schema::getForeignKeys($table))
            ->filter(fn ($foreignKey) => count($foreignKey['columns']) === 1)
            ->mapWithKeys(fn ($foreignKey) => [
                $foreignKey['columns'][0] => [
                    'table' => $foreignKey['foreign_table'],
                    'column' => $foreignKey['foreign_columns'][0] ?? 'id',
                ],
            ])
            ->all();
    }

    protected static function systemColumns(Model $model): array
    {
        $columns = [$model->getKeyName()];

        if ($model->usesTimestamps()) {
            $columns[] = $model->getCreatedAtColumn();
            $columns[] = $model->getUpdatedAtColumn();
        }

        if (method_exists($model, 'getDeletedAtColumn')) {
            $columns[] = $model->getDeletedAtColumn();
        }

        return array_values(array_filter($columns));
    }
}
