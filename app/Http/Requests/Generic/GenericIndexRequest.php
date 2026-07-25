<?php

namespace App\Http\Requests\Generic;

use App\Http\Requests\Concerns\ResolvesModelFromRoute;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $columns = $this->availableColumns();

            foreach (array_keys($this->input('filter', [])) as $column) {
                if (! in_array($column, $columns, true)) {
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
     * @return array<int, string>
     */
    public function availableColumns(): array
    {
        $model = new ($this->modelClass());

        return Schema::getColumnListing($model->getTable());
    }
}
