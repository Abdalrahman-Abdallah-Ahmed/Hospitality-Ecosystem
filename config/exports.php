<?php

return [

    /*
    |--------------------------------------------------------------------------
    | List exports (specs/021-module-data-export)
    |--------------------------------------------------------------------------
    |
    | Reservations, activities, bookings and guests download as Excel, CSV or
    | SQL in the same request. An export matching more records than this is
    | refused, never truncated (FR-016): a cut-short file looks complete.
    |
    */

    'max_rows' => (int) env('EXPORT_MAX_ROWS', 10000),

];
