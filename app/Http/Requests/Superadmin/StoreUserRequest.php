<?php

namespace App\Http\Requests\Superadmin;

use App\Enums\GroupRole;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * `role` accepts every value on purpose, so that super_admin gets the
     * fixed 403 from UserAdministration rather than a 422 here. There is no
     * `password`: a new user sets their own from the emailed link.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users', 'phone_number')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'hotel_id' => ['required', 'uuid', Rule::exists('hotels', 'id')->withoutTrashed()],
            'hotel_group_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('hotel_groups', 'id')->withoutTrashed()],
            'group_role' => ['sometimes', 'nullable', Rule::enum(GroupRole::class)],
        ];
    }
}
