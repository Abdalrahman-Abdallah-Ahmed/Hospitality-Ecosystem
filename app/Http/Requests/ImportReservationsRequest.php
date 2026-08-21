<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportReservationsRequest extends FormRequest
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
            // 'txt' is included alongside 'csv' because PHP's fileinfo
            // sometimes sniffs a small/simple CSV's content as text/plain.
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120'],
        ];
    }
}
