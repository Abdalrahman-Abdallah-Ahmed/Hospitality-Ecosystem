<?php

namespace App\Enums;

/**
 * The values an admin can place on a voucher template (FR-018). List fields
 * print one item per line; every other field is a single text value.
 */
enum VoucherField: string
{
    case HOTEL_NAME = 'hotel.name';
    case HOTEL_ADDRESS = 'hotel.address';
    case HOTEL_PHONE = 'hotel.phone';
    case HOTEL_EMAIL = 'hotel.email';
    case RESERVATION_REFERENCE = 'reservation.reference';
    case RESERVATION_STATUS = 'reservation.status';
    case GUEST_PRIMARY_NAME = 'guest.primary_name';
    case GUESTS_OTHERS = 'guests.others';
    case GUESTS_ALL = 'guests.all';
    case STAY_ARRIVAL_DATE = 'stay.arrival_date';
    case STAY_DEPARTURE_DATE = 'stay.departure_date';
    case STAY_NIGHTS = 'stay.nights';
    case ROOMS_LINES = 'rooms.lines';
    case PARTY_ADULTS = 'party.adults';
    case PARTY_CHILDREN = 'party.children';
    case PARTY_CHILDREN_AGES = 'party.children_ages';
    case SPECIAL_REQUESTS = 'reservation.special_requests';
    case TOTAL = 'reservation.total';
    case STAFF_NOTE = 'voucher.staff_note';
    case ISSUE_DATE = 'voucher.issue_date';
    case FREE_TEXT = 'free_text';

    public function kind(): string
    {
        return $this->isList() ? 'list' : 'text';
    }

    public function isList(): bool
    {
        return in_array($this, [self::GUESTS_OTHERS, self::GUESTS_ALL, self::ROOMS_LINES], true);
    }

    /**
     * Values written left to right whatever the voucher language: in an
     * Arabic voucher their digit groups would otherwise be reordered.
     */
    public function isLtrValue(): bool
    {
        return in_array($this, [self::HOTEL_PHONE, self::HOTEL_EMAIL, self::RESERVATION_REFERENCE, self::TOTAL], true);
    }

    public function label(VoucherLanguage $language): string
    {
        // The keys contain dots, which __() would read as nesting, so index the array.
        return __('voucher.fields', [], $language->value)[$this->value] ?? $this->value;
    }
}
