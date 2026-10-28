<?php

namespace App\Http\Requests\Vouchers;

use App\Enums\VoucherLanguage;
use App\Http\Requests\Vouchers\Concerns\ValidatesVoucherImage;
use App\Models\VoucherTemplate;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading a voucher template (SPEC-013, FR-010/011). The name is unique per
 * hotel among live templates, case-insensitively.
 */
class StoreVoucherTemplateRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:120', static::uniqueName(fn () => resolveHotel($this->user(), $this->input('hotel_id'))?->id)],
            'language' => ['required', Rule::enum(VoucherLanguage::class)],
            'is_default' => ['sometimes', 'boolean'],
            'hotel_id' => ['sometimes', 'nullable', 'uuid'],
            ...$this->imageRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->checkImage($validator));
    }

    /**
     * Unique per hotel among live templates, ignoring case, like the partial
     * unique index. `Rule::unique` compares exactly, and on create the hotel
     * comes from resolveHotel(), so this is a closure rule. A request without
     * a hotel is left to the controller's 403.
     *
     * @param  Closure(): ?string  $hotelId
     */
    public static function uniqueName(Closure $hotelId, ?string $exceptId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hotelId, $exceptId): void {
            $hotel = $hotelId();

            if (! is_string($value) || $hotel === null) {
                return;
            }

            $taken = VoucherTemplate::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel)
                ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($taken) {
                $fail('A voucher template with this name already exists in this hotel.');
            }
        };
    }
}
