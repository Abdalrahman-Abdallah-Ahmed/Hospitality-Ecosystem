<?php

namespace App\Http\Requests\Generic;

use App\Http\Requests\Concerns\ResolvesModelFromRoute;
use App\Support\RequestRules\ModelColumnRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Drop-in "store" request for any resource controller: rules are derived
 * from the target model's fillable columns, so no per-model request is
 * needed for plain CRUD endpoints.
 */
class GenericStoreRequest extends FormRequest
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
        return ModelColumnRules::forCreate($this->modelClass());
    }
}
