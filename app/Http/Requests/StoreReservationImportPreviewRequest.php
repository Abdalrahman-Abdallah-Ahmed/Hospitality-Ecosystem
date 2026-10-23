<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An upload for column mapping (016): the same files a direct import
 * accepts.
 */
class StoreReservationImportPreviewRequest extends FormRequest
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
            // Super admins name the hotel; everyone else uploads into their own.
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }
}
