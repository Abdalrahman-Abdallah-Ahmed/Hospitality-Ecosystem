<?php

namespace App\Http\Requests\Vouchers;

use App\Http\Requests\Vouchers\Concerns\ValidatesVoucherImage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replacing a template's image; the layout is kept (FR-014).
 */
class VoucherTemplateImageRequest extends FormRequest
{
    use ValidatesVoucherImage;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->imageRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->checkImage($validator));
    }
}
