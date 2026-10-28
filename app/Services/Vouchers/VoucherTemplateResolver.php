<?php

namespace App\Services\Vouchers;

use App\Enums\VoucherLanguage;
use App\Models\Reservation;
use App\Models\VoucherTemplate;

/**
 * Picks the template and language for a voucher (research R7, FR-005/016):
 *
 * 1. A template named by staff is used whatever its default status, and its
 *    language wins. Looked up through the tenant scope, so another hotel's
 *    or a deleted template is not found (404).
 * 2. Otherwise the primary guest's language decides, and the hotel's default
 *    template for that language is used.
 * 3. Otherwise the built-in layout (null template).
 */
class VoucherTemplateResolver
{
    /**
     * @return array{0: ?VoucherTemplate, 1: VoucherLanguage}
     */
    public function resolve(Reservation $reservation, ?string $templateId = null): array
    {
        if ($templateId !== null) {
            $template = VoucherTemplate::query()
                ->where('hotel_id', $reservation->hotel_id)
                ->findOrFail($templateId);

            return [$template, $template->language];
        }

        $language = VoucherLanguage::fromGuestLanguage($reservation->primaryGuest()?->preferred_language);

        $template = VoucherTemplate::query()
            ->where('hotel_id', $reservation->hotel_id)
            ->defaultFor($language)
            ->first();

        return [$template, $language];
    }
}
