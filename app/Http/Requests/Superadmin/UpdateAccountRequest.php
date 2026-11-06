<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
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
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('hotel_groups', 'slug')->ignore($this->route('account'))],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_timezone' => ['sometimes', 'string', 'timezone'],
        ];
    }
}
