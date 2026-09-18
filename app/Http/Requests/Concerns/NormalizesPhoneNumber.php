<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * Accepts `phone_number` however a person typed it and validates its
 * canonical digits-only form, so a number entered as "+20 115 179 3758" in the
 * dashboard and "201151793758" from Meta's webhook are stored and matched as
 * the same number.
 */
trait NormalizesPhoneNumber
{
    protected function prepareForValidation(): void
    {
        if (is_string($phone = $this->input('phone_number'))) {
            $this->merge(['phone_number' => PhoneNumber::digits($phone)]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function phoneNumberRules(): array
    {
        return ['required', 'digits_between:'.PhoneNumber::MIN_DIGITS.','.PhoneNumber::MAX_DIGITS];
    }
}
