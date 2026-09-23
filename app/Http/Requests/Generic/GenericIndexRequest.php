<?php

namespace App\Http\Requests\Generic;

use App\Enums\InsightTypes;
use App\Http\Requests\Concerns\ResolvesModelFromRoute;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
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

            foreach (array_keys($this->input('filter', [])) as $column) {
                if (! in_array($column, $filterColumns, true)) {
                    $validator->errors()->add("filter.{$column}", "Unknown filter column [{$column}].");
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
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        $model = new ($this->modelClass());

        return Schema::getColumnListing($model->getTable());
    }
}
