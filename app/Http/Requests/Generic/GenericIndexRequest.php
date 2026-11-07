<?php

namespace App\Http\Requests\Generic;

use App\Enums\InsightTypes;
use App\Http\Requests\Concerns\ResolvesModelFromRoute;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Drop-in "index" request for any resource controller: validates the
 * generic listing query string (search, sort, pagination, column
 * filters) against the target model's actual table columns.
 */
class GenericIndexRequest extends FormRequest
{
    use ResolvesModelFromRoute;

    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'array'],
            'insight_type' => ['nullable', 'string', Rule::enum(InsightTypes::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $columns = $this->availableColumns();
            $filterColumns = $this->filterColumns();
            $filters = (array) $this->input('filter', []);
            $types = $filters === [] ? [] : $this->columnTypes();

            foreach ($filters as $column => $value) {
                if (! in_array($column, $filterColumns, true)) {
                    $validator->errors()->add("filter.{$column}", "Unknown filter column [{$column}].");
                } elseif (! $this->filterValueFits($types[$column] ?? 'text', $value)) {
                    $validator->errors()->add("filter.{$column}", "The value for filter [{$column}] is not valid.");
                }
            }

            $sort = ltrim((string) $this->input('sort', ''), '-');

            if ($sort !== '' && ! in_array($sort, $columns, true)) {
                $validator->errors()->add('sort', "Unknown sort column [{$sort}].");
            }
        });
    }

    /**
     * The keys `filter` accepts. The table's columns by default; a subclass
     * adds virtual filters its controller applies itself (they stay unusable
     * for sort).
     *
     * @return array<int, string>
     */
    protected function filterColumns(): array
    {
        return $this->availableColumns();
    }

    /**
     * The columns `search` reads, or null for the model's own list. A subclass
     * narrows it when some columns must not be searched by this caller.
     *
     * @return array<int, string>|null
     */
    public function searchColumns(): ?array
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        $model = new ($this->modelClass());

        return Schema::getColumnListing($model->getTable());
    }

    /**
     * @return array<string, string> column name => database type
     */
    private function columnTypes(): array
    {
        $table = (new ($this->modelClass()))->getTable();

        return collect(Schema::getColumns($table))->pluck('type_name', 'name')->all();
    }

    /**
     * A value Postgres can't compare to the column would otherwise fail the
     * query and answer 500. Null and empty mean "no filter" to Filterable, and
     * an array means any of its values.
     */
    private function filterValueFits(string $type, mixed $value): bool
    {
        if (is_array($value)) {
            return array_all($value, fn ($item) => ! is_array($item) && $this->filterValueFits($type, $item));
        }

        if ($value === null || $value === '') {
            return true;
        }

        $text = (string) $value;

        return match ($type) {
            'uuid' => Str::isUuid($text),
            'int2', 'int4', 'int8' => filter_var($text, FILTER_VALIDATE_INT) !== false,
            'numeric', 'float4', 'float8' => is_numeric($text),
            'bool' => filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
            'date', 'timestamp', 'timestamptz' => $this->isIsoDate($text),
            'json', 'jsonb', 'vector' => false,
            default => true,
        };
    }

    private function isIsoDate(string $text): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?)?$/', $text, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && strtotime($text) !== false;
    }
}
