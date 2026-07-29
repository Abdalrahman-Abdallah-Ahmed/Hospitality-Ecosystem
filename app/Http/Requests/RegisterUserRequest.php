<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'hotel' => ['required', 'array'],
            'hotel.name' => ['required', 'string', 'max:255', 'unique:hotels,name'],
            'hotel.city' => ['required', 'string', 'max:255'],
            'hotel.country_code' => ['nullable', 'string', 'size:2'],
            'hotel.address' => ['nullable', 'string', 'max:255'],
            'hotel.timezone' => ['nullable', 'string', 'max:255'],
            'hotel.currency' => ['nullable', 'string', 'size:3'],
            'hotel.email' => ['nullable', 'string', 'email', 'max:255', 'unique:hotels,email'],
            'hotel.phone' => ['nullable', 'string', 'max:255'],
            'hotel.whatsapp_number' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }
}
