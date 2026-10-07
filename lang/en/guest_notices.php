<?php

/*
 * Fixed messages telling a guest how their request ended (SPEC-007). Never
 * AI-written, and never carrying task titles, descriptions or staff names.
 */
return [

    'completed' => 'Hello :first_name, your :kind request at :hotel has been taken care of. Reply here if anything else is needed.',

    'cancellation_approved' => 'Hello :first_name, your booking for :item on :date (ref. :reference) at :hotel has been cancelled as you asked.',

    'cancellation_declined' => 'Hello :first_name, your booking for :item on :date (ref. :reference) at :hotel is still in place. :note',

    'email_subject_completed' => ':hotel: your :kind request is done',

    'email_subject_cancellation_approved' => ':hotel: your booking :reference is cancelled',

    'email_subject_cancellation_declined' => ':hotel: your booking :reference is still in place',

    'kinds' => [
        'housekeeping' => 'housekeeping',
        'service' => 'service',
        'maintenance' => 'maintenance',
        'room_change' => 'room change',
    ],

];
