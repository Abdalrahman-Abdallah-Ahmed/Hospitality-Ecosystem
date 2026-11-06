<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * The slug's unique index covers soft-deleted accounts too, so this rule
     * does as well: a deleted account's slug must be a 422, not a 500.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('hotel_groups', 'slug')],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }
}
