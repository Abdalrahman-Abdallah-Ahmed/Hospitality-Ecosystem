<?php

return [

    'title' => 'Reservation Voucher',

    'fields' => [
        'hotel.name' => 'Hotel name',
        'hotel.address' => 'Hotel address',
        'hotel.phone' => 'Hotel phone',
        'hotel.email' => 'Hotel email',
        'reservation.reference' => 'Reservation reference',
        'reservation.status' => 'Reservation status',
        'guest.primary_name' => 'Primary guest',
        'guests.others' => 'Other guests',
        'guests.all' => 'All guests',
        'stay.arrival_date' => 'Arrival date',
        'stay.departure_date' => 'Departure date',
        'stay.nights' => 'Nights',
        'rooms.lines' => 'Room lines',
        'party.adults' => 'Adults',
        'party.children' => 'Children',
        'party.children_ages' => "Children's ages",
        'reservation.special_requests' => 'Special requests',
        'reservation.total' => 'Total',
        'voucher.staff_note' => 'Staff note',
        'voucher.issue_date' => 'Issue date',
        'free_text' => 'Free text',
    ],

    'labels' => [
        'reservation' => 'Reservation',
        'status' => 'Status',
        'guest' => 'Guest',
        'other_guests' => 'Other guests',
        'arrival' => 'Arrival',
        'departure' => 'Departure',
        'nights' => 'Nights',
        'rooms' => 'Rooms',
        'adults' => 'Adults',
        'children' => 'Children',
        'children_ages' => "Children's ages",
        'special_requests' => 'Special requests',
        'total' => 'Total',
        'note' => 'Note',
        'issued' => 'Issued',
    ],

    'statuses' => [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked in',
        'checked_out' => 'Checked out',
        'cancelled' => 'Cancelled',
        'no_show' => 'No-show',
    ],

    'list_separator' => ', ',

    'more' => '+:count more',

    'mail' => [
        'subject' => 'Your reservation voucher – :reference',
        'body' => "Dear guest,\n\nPlease find attached the voucher for your reservation :reference at :hotel.\n\nWe look forward to welcoming you.",
    ],

    'errors' => [
        'ineligible' => 'A voucher cannot be issued for a cancelled or no-show reservation.',
        'no_recipient' => 'The guest has no email address. Enter one to send the voucher.',
        'daily_cap' => 'Too many voucher emails for this reservation. Try again later.',
        'user_hourly_cap' => 'You have sent too many voucher emails in the last hour. Try again later.',
        'hotel_contact_on_email' => 'The hotel details cannot be changed on an emailed voucher. Download it instead.',
        'not_overridable' => 'This field cannot be overridden.',
        'departure_before_arrival' => 'The displayed departure date must be after the displayed arrival date.',
    ],

];
