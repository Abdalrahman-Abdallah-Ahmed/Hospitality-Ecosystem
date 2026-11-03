<?php

namespace App\Support\Export;

use App\Enums\ReservationChannels;
use App\Models\Reservation;

/**
 * The reservation list as a spreadsheet: one row per reservation, its rooms
 * and party guests joined into single cells (spec 021, clarification Q2).
 */
class ReservationsExport extends ModuleExport
{
    public function title(): string
    {
        return 'Reservations';
    }

    protected function relations(): array
    {
        // Constrained first, so the nested loads reuse the live lines only.
        return [
            'guest',
            'reservationGuests.guest',
            'reservationRooms' => fn ($lines) => $lines->active(),
            'reservationRooms.roomType',
            'reservationRooms.room',
        ];
    }

    protected function dateColumns(): array
    {
        return ['Arrival Date', 'Departure Date'];
    }

    protected function dateTimeColumns(): array
    {
        return ['Created At'];
    }

    protected function columns(): array
    {
        return [
            'Reservation Number' => fn (Reservation $r) => $r->reservation_id,
            'Guest Name' => fn (Reservation $r) => $this->fullName($r->guest),
            'Guest Email' => fn (Reservation $r) => $r->guest?->email,
            'Guest Phone' => fn (Reservation $r) => $r->guest?->phone_number,
            'Arrival Date' => fn (Reservation $r) => $r->arrival_date,
            'Departure Date' => fn (Reservation $r) => $r->departure_date,
            'Nights' => fn (Reservation $r) => $r->arrival_date && $r->departure_date
                ? (int) $r->arrival_date->diffInDays($r->departure_date)
                : null,
            'Adults' => fn (Reservation $r) => $r->adults,
            'Children' => fn (Reservation $r) => $r->children,
            'Room Types' => fn (Reservation $r) => $this->joined($r->reservationRooms->map(fn ($line) => $line->roomType?->name)),
            'Rooms' => fn (Reservation $r) => $this->joined($r->reservationRooms->map(fn ($line) => $line->room?->room_number)),
            'Party Guests' => fn (Reservation $r) => $this->joined($r->reservationGuests->map(fn ($party) => $this->fullName($party->guest))),
            'Status' => fn (Reservation $r) => $this->label($r->status),
            'Source' => fn (Reservation $r) => $this->label($r->source, ReservationChannels::class),
            'Reservation Value' => fn (Reservation $r) => $this->amount($r->reservation_value),
            'Currency' => fn (Reservation $r) => $r->currency,
            'Special Requests' => fn (Reservation $r) => $r->special_requests,
            'Created At' => fn (Reservation $r) => $r->created_at,
        ];
    }
}
