<?php

namespace App\Enums;

/**
 * The language a voucher is printed in (SPEC-013). Arabic is laid out right
 * to left; anything that is not Arabic prints in English.
 */
enum VoucherLanguage: string
{
    case EN = 'en';
    case AR = 'ar';

    /**
     * `ar`, `ar-SA`, `AR` … are Arabic; every other code, or none, is English.
     */
    public static function fromGuestLanguage(?string $code): self
    {
        return str_starts_with(strtolower(trim((string) $code)), 'ar') ? self::AR : self::EN;
    }

    public function isRtl(): bool
    {
        return $this === self::AR;
    }
}
