<?php

namespace App\Http\Requests;

use App\Enums\ImportDateOrder;
use App\Enums\ImportDecimalMark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // How "12/09/2025" is read for the whole file (FR-008a).
            'date_order' => ['nullable', Rule::enum(ImportDateOrder::class)],
            // How "25,200" is read: "." or "," as the decimal mark (016).
            'decimal_mark' => ['nullable', Rule::enum(ImportDecimalMark::class)],
            'dry_run' => ['nullable', 'boolean'],
            // Super admins name the hotel; everyone else imports into their own.
            'hotel_id' => ['nullable', 'uuid'],
        ];
    }
}
