<?php

namespace App\Services\Vouchers;

use App\Enums\ReservationRoomStatus;
use App\Enums\VoucherField;
use App\Enums\VoucherLanguage;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\ReservationRoom;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Turns a reservation, plus the per-request overrides and staff note, into
 * the localized values a voucher prints (SPEC-013, FR-002, FR-007).
 *
 * Read-only: overrides change only the returned VoucherData, never a model.
 * Room lines print the room type only, never a room number.
 */
class VoucherDataBuilder
{
    /**
     * The keys a request may override (FR-007).
     */
    public const OVERRIDABLE = [
        'primary_guest_name', 'other_guest_names', 'arrival_date', 'departure_date',
        'room_lines', 'adults', 'children', 'children_ages', 'special_requests',
        'hotel_name', 'hotel_address', 'hotel_phone', 'hotel_email',
    ];

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function fromReservation(
        Reservation $reservation,
        VoucherLanguage $language,
        bool $showTotal = false,
        ?string $staffNote = null,
        array $overrides = [],
    ): VoucherData {
        $reservation->loadMissing([
            'hotel',
            'guest',
            'reservationGuests.guest',
            'reservationRooms.roomType' => fn ($query) => $query->withTrashed(),
        ]);

        /** @var Hotel $hotel */
        $hotel = $reservation->hotel;

        [$primary, $others] = $this->guestNames($reservation);
        $primary = $overrides['primary_guest_name'] ?? $primary;
        $others = $overrides['other_guest_names'] ?? $others;

        $arrival = CarbonImmutable::parse($overrides['arrival_date'] ?? $reservation->arrival_date);
        $departure = CarbonImmutable::parse($overrides['departure_date'] ?? $reservation->departure_date);

        $rooms = $overrides['room_lines'] ?? $reservation->reservationRooms
            ->filter(fn (ReservationRoom $line) => $line->status !== ReservationRoomStatus::CANCELLED)
            ->map(fn (ReservationRoom $line) => (string) $line->roomType?->name)
            ->filter()
            ->values()
            ->all();

        $total = $showTotal && $reservation->reservation_value !== null
            ? number_format((float) $reservation->reservation_value, 2).' '.($reservation->currency ?? $hotel->currency)
            : null;

        $text = [
            VoucherField::HOTEL_NAME->value => $overrides['hotel_name'] ?? $hotel->name,
            VoucherField::HOTEL_ADDRESS->value => $overrides['hotel_address'] ?? $hotel->address,
            VoucherField::HOTEL_PHONE->value => $overrides['hotel_phone'] ?? $hotel->phone,
            VoucherField::HOTEL_EMAIL->value => $overrides['hotel_email'] ?? $hotel->email,
            VoucherField::RESERVATION_REFERENCE->value => $this->reference($reservation),
            VoucherField::RESERVATION_STATUS->value => __('voucher.statuses.'.$reservation->status->value, [], $language->value),
            VoucherField::GUEST_PRIMARY_NAME->value => $primary,
            VoucherField::STAY_ARRIVAL_DATE->value => $this->date($arrival, $language),
            VoucherField::STAY_DEPARTURE_DATE->value => $this->date($departure, $language),
            VoucherField::STAY_NIGHTS->value => (string) max(0, (int) $arrival->diffInDays($departure)),
            VoucherField::PARTY_ADULTS->value => $this->number($overrides['adults'] ?? $reservation->adults),
            VoucherField::PARTY_CHILDREN->value => $this->number($overrides['children'] ?? $reservation->children),
            VoucherField::PARTY_CHILDREN_AGES->value => $this->ages($overrides['children_ages'] ?? $reservation->children_ages, $language),
            VoucherField::SPECIAL_REQUESTS->value => $overrides['special_requests'] ?? $reservation->special_requests,
            VoucherField::TOTAL->value => $total,
            VoucherField::STAFF_NOTE->value => $staffNote,
            VoucherField::ISSUE_DATE->value => $this->date(CarbonImmutable::now($hotel->timezone ?? config('app.timezone')), $language),
        ];

        return new VoucherData(
            language: $language,
            text: $text,
            lists: [
                VoucherField::GUESTS_OTHERS->value => array_values($others),
                VoucherField::GUESTS_ALL->value => array_values(array_filter([$primary, ...$others])),
                VoucherField::ROOMS_LINES->value => array_values($rooms),
            ],
            reference: $this->reference($reservation),
        );
    }

    /**
     * Plausible values for previewing a template without a reservation
     * (FR-025): one long primary name and several guests and rooms, so the
     * admin sees how fitting behaves. Overrides apply as they would to a
     * reservation.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sample(VoucherLanguage $language, bool $showTotal = false, ?string $staffNote = null, array $overrides = []): VoucherData
    {
        $ar = $language->isRtl();
        $primary = $overrides['primary_guest_name'] ?? ($ar ? 'عبدالرحمن بن محمد بن عبدالله الهاشمي' : 'Alexandra Montgomery-Fitzgerald Wellington');
        $others = $overrides['other_guest_names'] ?? ($ar ? ['سارة الهاشمي', 'Daniel Smith', 'ليلى الهاشمي'] : ['Sarah Wellington', 'Omar Haddad', 'Lucas Wellington']);
        $arrival = CarbonImmutable::parse($overrides['arrival_date'] ?? CarbonImmutable::now()->addDays(14)->startOfDay());
        $departure = CarbonImmutable::parse($overrides['departure_date'] ?? $arrival->addDays(3));

        return new VoucherData(
            language: $language,
            text: [
                VoucherField::HOTEL_NAME->value => $overrides['hotel_name'] ?? ($ar ? 'فندق الميناء الكبير' : 'Grand Harbor Hotel'),
                VoucherField::HOTEL_ADDRESS->value => $overrides['hotel_address'] ?? ($ar ? 'شارع الكورنيش ١٢، دبي' : '12 Corniche Road, Dubai'),
                VoucherField::HOTEL_PHONE->value => $overrides['hotel_phone'] ?? '+971 4 000 0000',
                VoucherField::HOTEL_EMAIL->value => $overrides['hotel_email'] ?? 'reservations@example.com',
                VoucherField::RESERVATION_REFERENCE->value => 'SAMPLE-0001',
                VoucherField::RESERVATION_STATUS->value => __('voucher.statuses.confirmed', [], $language->value),
                VoucherField::GUEST_PRIMARY_NAME->value => $primary,
                VoucherField::STAY_ARRIVAL_DATE->value => $this->date($arrival, $language),
                VoucherField::STAY_DEPARTURE_DATE->value => $this->date($departure, $language),
                VoucherField::STAY_NIGHTS->value => (string) max(0, (int) $arrival->diffInDays($departure)),
                VoucherField::PARTY_ADULTS->value => $this->number($overrides['adults'] ?? 2),
                VoucherField::PARTY_CHILDREN->value => $this->number($overrides['children'] ?? 2),
                VoucherField::PARTY_CHILDREN_AGES->value => $this->ages($overrides['children_ages'] ?? [4, 9], $language),
                VoucherField::SPECIAL_REQUESTS->value => $overrides['special_requests'] ?? ($ar
                    ? 'نرجو تجهيز سرير إضافي للأطفال. سنصل في وقت متأخر من الليل.'
                    : 'Please prepare an extra bed for the children. We will arrive late at night.'),
                VoucherField::TOTAL->value => $showTotal ? '1,250.00 USD' : null,
                VoucherField::STAFF_NOTE->value => $staffNote,
                VoucherField::ISSUE_DATE->value => $this->date(CarbonImmutable::now(), $language),
            ],
            lists: [
                VoucherField::GUESTS_OTHERS->value => array_values($others),
                VoucherField::GUESTS_ALL->value => array_values(array_filter([$primary, ...$others])),
                VoucherField::ROOMS_LINES->value => $overrides['room_lines'] ?? ($ar ? ['غرفة ديلوكس', 'غرفة ديلوكس', 'جناح عائلي'] : ['Deluxe Room', 'Deluxe Room', 'Family Suite']),
            ],
            reference: 'SAMPLE-0001',
        );
    }

    public function reference(Reservation $reservation): string
    {
        return (string) ($reservation->reservation_id ?: $reservation->id);
    }

    /**
     * The primary guest's name and the other guests' names. Falls back to the
     * reservation's guest pointer when the guest list is empty.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function guestNames(Reservation $reservation): array
    {
        $primaryGuest = $reservation->primaryGuest();

        $others = $reservation->reservationGuests
            ->reject(fn (ReservationGuest $link) => $link->guest_id === $primaryGuest?->id)
            ->map(fn (ReservationGuest $link) => $this->name($link->guest))
            ->filter()
            ->values()
            ->all();

        return [$this->name($primaryGuest), $others];
    }

    private function name(?Guest $guest): ?string
    {
        $name = trim(($guest?->first_name ?? '').' '.($guest?->last_name ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * `12 December 2026` / `12 ديسمبر 2026`, always with Western digits (spec
     * Assumptions).
     */
    private function date(CarbonInterface $date, VoucherLanguage $language): string
    {
        return $this->westernDigits($date->locale($language->value)->translatedFormat('j F Y'));
    }

    private function number(mixed $value): ?string
    {
        return $value === null ? null : (string) (int) $value;
    }

    /**
     * @param  list<int>|null  $ages
     */
    private function ages(?array $ages, VoucherLanguage $language): ?string
    {
        if (empty($ages)) {
            return null;
        }

        return implode(__('voucher.list_separator', [], $language->value), array_map('intval', $ages));
    }

    private function westernDigits(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
