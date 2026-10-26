<?php

return [

    /*
    | Demo data (the sample hotel, its knowledge, guests and reservations).
    | Seeded in local and testing, and elsewhere only when asked for, so a
    | production install never starts with a fake hotel (spec 017 FR-030).
    */
    'demo' => (bool) env('SEED_DEMO', false),

    /*
    | The platform's first super admin. In production the password must be
    | given, and so must the email; PlatformSeeder refuses to create a
    | known-password or demo-email account there.
    */
    'super_admin' => [
        'name' => env('SEED_SUPER_ADMIN_NAME', 'Hospitality Admin'),
        'email' => env('SEED_SUPER_ADMIN_EMAIL'),
        'password' => env('SEED_SUPER_ADMIN_PASSWORD'),
        'phone_number' => env('SEED_SUPER_ADMIN_PHONE'),
    ],

];
