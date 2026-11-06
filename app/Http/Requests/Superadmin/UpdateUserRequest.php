<?php

namespace App\Http\Requests\Superadmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return apiAuth();
    }

    /**
     * Details only. What a user can reach goes through UpdateUserAccessRequest,
     * and there is no `password`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users', 'phone_number')->ignore($this->route('user'))],
        ];
    }
}
