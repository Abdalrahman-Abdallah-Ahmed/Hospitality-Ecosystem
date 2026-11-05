<?php

namespace App\Support\Export;

use App\Models\Booking;

/**
 * The booking list as a spreadsheet (spec 021). Related records show by
 * name; a removed activity leaves its cell empty.
 */
class BookingsExport extends ModuleExport
{
    public function title(): string
    {
        return 'Bookings';
    }

    protected function relations(): array
    {
        return ['guest', 'activity', 'reservation', 'createdBy'];
    }

    protected function dateTimeColumns(): array
    {
        return ['Scheduled For', 'Confirmed At', 'Realised At', 'Cancelled At'];
    }

    protected function columns(): array
    {
        return [
            'Reference' => fn (Booking $b) => $b->reference,
            'Item Name' => fn (Booking $b) => $b->item_name,
            'Activity' => fn (Booking $b) => $b->activity?->name,
            'Guest Name' => fn (Booking $b) => $this->fullName($b->guest),
            'Reservation Number' => fn (Booking $b) => $b->reservation?->reservation_id,
            'Status' => fn (Booking $b) => $this->label($b->status),
            'Scheduled For' => fn (Booking $b) => $b->scheduled_for,
            'Guests (Pax)' => fn (Booking $b) => $b->pax,
            'Charge Model' => fn (Booking $b) => $this->label($b->charge_model),
            'Expected Value' => fn (Booking $b) => $this->amount($b->expected_value),
            'Currency' => fn (Booking $b) => $b->currency,
            'Origin' => fn (Booking $b) => $this->label($b->origin),
            'Channel' => fn (Booking $b) => $this->label($b->channel),
            'Created By' => fn (Booking $b) => $b->createdBy?->name,
            'Confirmed At' => fn (Booking $b) => $b->confirmed_at,
            'Realised At' => fn (Booking $b) => $b->realised_at,
            'Cancelled At' => fn (Booking $b) => $b->cancelled_at,
            'Cancellation Reason' => fn (Booking $b) => $b->cancellation_reason,
            'Notes' => fn (Booking $b) => $b->notes,
        ];
    }
}
