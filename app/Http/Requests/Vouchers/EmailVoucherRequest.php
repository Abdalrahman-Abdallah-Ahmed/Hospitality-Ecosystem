<?php

namespace App\Http\Requests\Vouchers;

use Illuminate\Contracts\Validation\Validator;

/**
 * Emailing a voucher (FR-026/027): the generation options plus a client key
 * that makes retries of one click send once, and an optional address that
 * replaces the primary guest's for this send only.
 *
 * The hotel's own contact block cannot be overridden here: the email goes
 * out from the hotel's verified sending domain, and changed contact details
 * would turn it into a convincing phishing message.
 */
class EmailVoucherRequest extends VoucherOptionsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'request_key' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $overrides = $this->input('overrides');

            foreach (self::HOTEL_CONTACT_OVERRIDES as $key) {
                if (is_array($overrides) && array_key_exists($key, $overrides)) {
                    $validator->errors()->add("overrides.{$key}", __('voucher.errors.hotel_contact_on_email'));
                }
            }
        });
    }
}
