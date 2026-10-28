<?php

namespace App\Http\Requests\Vouchers;

use App\Enums\VoucherLanguage;
use App\Models\VoucherTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming a template, changing its language, or making it a default.
 */
class UpdateVoucherTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = $this->route('voucherTemplate');
        $template = $template instanceof VoucherTemplate ? $template : null;

        return [
            'name' => ['sometimes', 'string', 'max:120', StoreVoucherTemplateRequest::uniqueName(fn () => $template?->hotel_id, $template?->id)],
            'language' => ['sometimes', Rule::enum(VoucherLanguage::class)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
