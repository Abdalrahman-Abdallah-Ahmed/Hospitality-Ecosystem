<?php

namespace App\Http\Requests\Generic;

use App\Http\Requests\Concerns\ResolvesModelFromRoute;
use App\Support\RequestRules\ModelColumnRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Drop-in "update" request for any resource controller: every column
 * rule is wrapped in "sometimes", and unique rules ignore the record
 * being updated, so partial updates work without a per-model request.
 */
class GenericUpdateRequest extends FormRequest
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
        return ModelColumnRules::forUpdate($this->modelClass(), $this->routeModel());
    }
}
