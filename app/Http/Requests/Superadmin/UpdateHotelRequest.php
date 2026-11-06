<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHotelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * The slug's unique index covers soft-deleted hotels too, so this rule does
     * as well: a deleted hotel's slug must be a 422, not a 500.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('hotels', 'slug')->ignore($this->route('hotel'))],
            'hotel_group_id' => ['sometimes', 'uuid', Rule::exists('hotel_groups', 'id')->withoutTrashed()],
        ];
    }
}
