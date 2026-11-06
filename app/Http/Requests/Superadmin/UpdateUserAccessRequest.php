<?php

namespace App\Http\Requests\Superadmin;

use App\Enums\GroupRole;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * `role` accepts every value on purpose, so that super_admin gets the
     * fixed 403 from UserAdministration rather than a 422 here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'group_role' => ['sometimes', 'nullable', Rule::enum(GroupRole::class)],
            'hotel_ids' => ['sometimes', 'array'],
            'hotel_ids.*' => ['uuid', Rule::exists('hotels', 'id')->withoutTrashed()],
            'staff_role_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
