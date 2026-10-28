<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;

/**
 * Everything a voucher prints, already localized and with overrides applied
 * (SPEC-013). Text fields hold a string or null; list fields hold a list of
 * strings. Built by VoucherDataBuilder, read by VoucherRenderer.
 */
final readonly class VoucherData
{
    /**
     * @param  array<string, ?string>  $text  keyed by text VoucherField values
     * @param  array<string, list<string>>  $lists  keyed by list VoucherField values
     */
    public function __construct(
        public VoucherLanguage $language,
        public array $text,
        public array $lists,
        public string $reference,
    ) {}

    /**
     * @return string|list<string>|null
     */
    public function value(VoucherField $field): string|array|null
    {
        if ($field->isList()) {
            return $this->lists[$field->value] ?? [];
        }

        $value = $this->text[$field->value] ?? null;

        return $value === null || trim($value) === '' ? null : $value;
    }

    public function has(VoucherField $field): bool
    {
        $value = $this->value($field);

        return is_array($value) ? $value !== [] : $value !== null;
    }
}
