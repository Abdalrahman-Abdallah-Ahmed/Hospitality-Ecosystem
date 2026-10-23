<?php

/*
|--------------------------------------------------------------------------
| Reservation import (SPEC-100/101)
|--------------------------------------------------------------------------
|
| The platform-wide translation tables the import uses to read legacy PMS
| exports. Headers are normalized first (lower-case; spaces, dashes, dots and
| other punctuation folded to "_"), then looked up here. Extending a table is
| how a new PMS's wording is supported — add a test with it.
*/

return [

    // Normalized header => canonical column.
    'header_synonyms' => [
        'reservation_id' => 'reservation_reference',
        'reservation_code' => 'reservation_reference',
        'reservation_no' => 'reservation_reference',
        'reservation_number' => 'reservation_reference',
        'res_id' => 'reservation_reference',
        'res_no' => 'reservation_reference',
        'res_number' => 'reservation_reference',
        'booking_ref' => 'reservation_reference',
        'booking_reference' => 'reservation_reference',
        'booking_id' => 'reservation_reference',
        'booking_no' => 'reservation_reference',
        'confirmation' => 'reservation_reference',
        'confirmation_no' => 'reservation_reference',
        'confirmation_number' => 'reservation_reference',
        'confirmation_code' => 'reservation_reference',
        'conf_no' => 'reservation_reference',
        'conf_number' => 'reservation_reference',
        'reference' => 'reservation_reference',

        'phone' => 'guest_phone',
        'phone_number' => 'guest_phone',
        'mobile' => 'guest_phone',
        'mobile_number' => 'guest_phone',
        'telephone' => 'guest_phone',
        'tel' => 'guest_phone',
        'guest_mobile' => 'guest_phone',
        'guest_phone_number' => 'guest_phone',

        'email' => 'guest_email',
        'e_mail' => 'guest_email',
        'email_address' => 'guest_email',
        'guest_e_mail' => 'guest_email',

        'first_name' => 'guest_first_name',
        'firstname' => 'guest_first_name',
        'given_name' => 'guest_first_name',
        'last_name' => 'guest_last_name',
        'lastname' => 'guest_last_name',
        'surname' => 'guest_last_name',
        'family_name' => 'guest_last_name',
        'guest_name' => 'guest_full_name',
        'name' => 'guest_full_name',
        'full_name' => 'guest_full_name',

        'guest_id' => 'guest_external_id',
        'guest_code' => 'guest_external_id',
        'guest_no' => 'guest_external_id',
        'profile_id' => 'guest_external_id',
        'external_guest_id' => 'guest_external_id',
        'profile_no' => 'guest_external_id',
        'profile_number' => 'guest_external_id',

        'primary' => 'is_primary',
        'primary_guest' => 'is_primary',
        'main_guest' => 'is_primary',

        'arrival' => 'arrival_date',
        'arrival_dt' => 'arrival_date',
        'arr' => 'arrival_date',
        'arr_date' => 'arrival_date',
        'check_in' => 'arrival_date',
        'check_in_date' => 'arrival_date',
        'checkin' => 'arrival_date',
        'checkin_date' => 'arrival_date',
        'date_in' => 'arrival_date',
        'departure' => 'departure_date',
        'departure_dt' => 'departure_date',
        'dep' => 'departure_date',
        'dep_date' => 'departure_date',
        'check_out' => 'departure_date',
        'check_out_date' => 'departure_date',
        'checkout' => 'departure_date',
        'checkout_date' => 'departure_date',
        'date_out' => 'departure_date',

        'room' => 'room_number',
        'room_no' => 'room_number',
        'room_num' => 'room_number',
        'rm' => 'room_number',
        'rm_no' => 'room_number',
        'room_category' => 'room_type',
        'room_type_name' => 'room_type',
        'category' => 'room_type',
        'rooms' => 'room_count',
        'no_of_rooms' => 'room_count',
        'number_of_rooms' => 'room_count',
        'room_qty' => 'room_count',
        'rms' => 'room_count',
        'no_rooms' => 'room_count',

        'reservation_status' => 'status',
        'booking_status' => 'status',
        'res_status' => 'status',
        'channel' => 'source',
        'booking_source' => 'source',
        'market_source' => 'source',

        'adult' => 'adults',
        'no_of_adults' => 'adults',
        'pax_adults' => 'adults',
        'child' => 'children',
        'kids' => 'children',
        'no_of_children' => 'children',
        'child_ages' => 'children_ages',
        'ages_of_children' => 'children_ages',

        'remarks' => 'special_requests',
        'comments' => 'special_requests',
        'notes' => 'special_requests',
        'requests' => 'special_requests',

        'amount' => 'reservation_value',
        'total' => 'reservation_value',
        'total_amount' => 'reservation_value',
        'rate' => 'reservation_value',
        'value' => 'reservation_value',
        'curr' => 'currency',

        'activity' => 'activity_name',
        'activity_title' => 'activity_name',
        'excursion' => 'activity_name',
        'activity_day' => 'activity_date',
        'activity_hour' => 'activity_time',
        'activity_guests' => 'activity_pax',
    ],

    // Every column the import reads. Anything else is reported once as
    // unrecognized.
    'canonical_columns' => [
        'reservation_reference', 'arrival_date', 'departure_date', 'status', 'source',
        'adults', 'children', 'children_ages', 'special_requests', 'reservation_value', 'currency',
        'room_number', 'room_type', 'floor', 'room_count',
        'guest_first_name', 'guest_last_name', 'guest_full_name', 'guest_phone', 'guest_email', 'guest_external_id',
        'is_primary', 'guests',
        'activity_name', 'activity_date', 'activity_time', 'activity_pax',
    ],

    // How each canonical column is named on the mapping screen. The frontend
    // translates these by key.
    'field_labels' => [
        'reservation_reference' => 'Reservation reference',
        'arrival_date' => 'Arrival date',
        'departure_date' => 'Departure date',
        'status' => 'Status',
        'source' => 'Source',
        'adults' => 'Adults',
        'children' => 'Children',
        'children_ages' => 'Children ages',
        'special_requests' => 'Special requests',
        'reservation_value' => 'Reservation value',
        'currency' => 'Currency',
        'room_number' => 'Room number',
        'room_type' => 'Room type',
        'floor' => 'Floor',
        'room_count' => 'Room count',
        'guest_first_name' => 'Guest first name',
        'guest_last_name' => 'Guest last name',
        'guest_full_name' => 'Guest full name',
        'guest_phone' => 'Guest phone',
        'guest_email' => 'Guest email',
        'guest_external_id' => 'Guest id (old system)',
        'is_primary' => 'Primary guest',
        'guests' => 'Guests (JSON list)',
        'activity_name' => 'Activity',
        'activity_date' => 'Activity date',
        'activity_time' => 'Activity time',
        'activity_pax' => 'Activity guests',
    ],

    // A file missing any of these fails before a row is read.
    'required_columns' => ['arrival_date', 'departure_date'],

    // At least one of these must be present as a column.
    'guest_identifier_columns' => ['guest_phone', 'guest_email', 'guest_external_id'],

    // Platform reservation status => legacy labels, matched ignoring case,
    // spaces, dashes and underscores. An empty status means pending.
    'status_labels' => [
        'confirmed' => ['confirmed', 'conf', 'guaranteed', 'definite', 'booked', 'reserved', 'reservation'],
        // A waitlisted request holds nothing yet, which is what pending means here.
        'pending' => ['pending', 'tentative', 'provisional', 'option', 'unconfirmed', 'waitlist', 'wait list', 'waitlisted'],
        'cancelled' => ['cancelled', 'canceled', 'cxl', 'cancel', 'cnl'],
        'checked_in' => ['checked in', 'checkedin', 'check in', 'in house', 'inhouse', 'arrived', 'ci', 'c/i', 'due out'],
        'checked_out' => ['checked out', 'checkedout', 'check out', 'departed', 'co', 'c/o'],
        'no_show' => ['no show', 'noshow', 'ns'],
    ],

    // Drafts written per WriteReservationImportJob run. Each job must finish
    // well under the database queue's retry_after (see config/queue.php).
    'write_chunk_size' => (int) env('RESERVATION_IMPORT_CHUNK_SIZE', 100),

    // Private local disk the uploaded file waits on until prepare ends.
    'disk' => 'local',

    // Column mapping previews (016): how long an upload waits to be mapped,
    // how many leading rows are kept for the mapping screen, and how many of
    // those may be chosen as the header row.
    'preview_ttl_hours' => 24,
    'preview_head_rows' => 30,
    'preview_header_rows' => 10,

];
