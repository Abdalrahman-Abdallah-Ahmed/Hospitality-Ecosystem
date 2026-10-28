<?php

namespace App\Http\Requests\Vouchers;

use App\Models\Reservation;
use App\Services\Vouchers\VoucherDataBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * How a voucher is generated (SPEC-013): which template, whether the total
 * shows, a staff note, and display overrides that change only the PDF
 * (FR-007). Shared by download, email and preview.
 */
class VoucherOptionsRequest extends FormRequest
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
        return static::optionRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function optionRules(): array
    {
        return [
            'template_id' => ['nullable', 'uuid'],
            'show_total' => ['sometimes', 'boolean'],
            'staff_note' => ['nullable', 'string', 'max:500'],
            'overrides' => ['nullable', 'array'],
            'overrides.primary_guest_name' => ['sometimes', 'string', 'max:200'],
            'overrides.other_guest_names' => ['sometimes', 'array', 'max:50'],
            'overrides.other_guest_names.*' => ['string', 'max:200'],
            'overrides.arrival_date' => ['sometimes', 'date'],
            'overrides.departure_date' => ['sometimes', 'date'],
            'overrides.room_lines' => ['sometimes', 'array', 'max:50'],
            'overrides.room_lines.*' => ['string', 'max:200'],
            'overrides.adults' => ['sometimes', 'integer', 'between:0,99'],
            'overrides.children' => ['sometimes', 'integer', 'between:0,99'],
            'overrides.children_ages' => ['sometimes', 'array', 'max:99'],
            'overrides.children_ages.*' => ['integer', 'between:0,17'],
            'overrides.special_requests' => ['sometimes', 'string', 'max:2000'],
            'overrides.hotel_name' => ['sometimes', 'string', 'max:255'],
            'overrides.hotel_address' => ['sometimes', 'string', 'max:255'],
            'overrides.hotel_phone' => ['sometimes', 'string', 'max:255'],
            'overrides.hotel_email' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => static::checkOverrides($validator, $this->input('overrides'), $this->reservationForDates()));
    }

    /**
     * Overrides of the hotel's own contact block. Allowed on a downloaded
     * voucher, never on one emailed from the hotel's sending domain, where
     * changed contact details would make a convincing phishing message.
     */
    public const HOTEL_CONTACT_OVERRIDES = ['hotel_name', 'hotel_address', 'hotel_phone', 'hotel_email'];

    /**
     * Rejects override keys outside FR-007 and a displayed departure that is
     * not after the displayed arrival (stored dates fill the side that is not
     * overridden).
     */
    public static function checkOverrides(Validator $validator, mixed $overrides, ?Reservation $reservation): void
    {
        if (! is_array($overrides)) {
            return;
        }

        foreach (array_keys($overrides) as $key) {
            if (! in_array($key, VoucherDataBuilder::OVERRIDABLE, true)) {
                $validator->errors()->add("overrides.{$key}", __('voucher.errors.not_overridable'));
            }
        }

        if (! isset($overrides['arrival_date']) && ! isset($overrides['departure_date'])) {
            return;
        }

        // Without a reservation (a sample preview) there is no stored date to
        // compare a lone override with; only a pair can contradict itself.
        if ($reservation === null && ! (isset($overrides['arrival_date']) && isset($overrides['departure_date']))) {
            return;
        }

        try {
            $arrival = CarbonImmutable::parse($overrides['arrival_date'] ?? $reservation?->arrival_date);
            $departure = CarbonImmutable::parse($overrides['departure_date'] ?? $reservation?->departure_date);
        } catch (\Throwable) {
            return; // the date rules report the bad value
        }

        if ($departure->lessThanOrEqualTo($arrival)) {
            $validator->errors()->add('overrides.departure_date', __('voucher.errors.departure_before_arrival'));
        }
    }

    /**
     * @return array{template_id: ?string, show_total: bool, staff_note: ?string, overrides: array<string, mixed>}
     */
    public function options(): array
    {
        return [
            'template_id' => $this->validated('template_id'),
            'show_total' => (bool) $this->validated('show_total', false),
            'staff_note' => $this->validated('staff_note'),
            'overrides' => $this->validated('overrides') ?? [],
        ];
    }

    protected function reservationForDates(): ?Reservation
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation ? $reservation : null;
    }
}
