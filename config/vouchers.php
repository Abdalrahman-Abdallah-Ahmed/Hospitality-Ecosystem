<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reservation vouchers (SPEC-013, specs/018-reservation-voucher)
    |--------------------------------------------------------------------------
    |
    | Template images live on a private disk and are only ever served through
    | the authenticated template image endpoint. Generated PDFs are never
    | stored.
    |
    */

    'disk' => env('VOUCHERS_DISK', 'local'),

    // Upload rules (FR-011). The long-side cap bounds GD memory while decoding.
    'max_upload_kb' => 5120,
    'min_short_side_px' => 1000,
    'max_long_side_px' => 6000,
    'max_aspect_ratio' => 3,

    // The normalized copy the PDF engine reads (research R3).
    'render_long_side_px' => 3508,
    'render_jpeg_quality' => 90,

    // Page and text fitting (research R4, R5).
    'page_long_side_mm' => 297,
    'min_font_pt' => 6,
    'max_layout_boxes' => 60,

    // Email (research R9, FR-027a).
    'email_claim_stale_minutes' => 5,
    'email_daily_cap' => 5,
    // Per staff user across all reservations, so the per-reservation cap
    // cannot be multiplied by looping over reservations.
    'email_user_hourly_cap' => 20,

    'mpdf_temp_dir' => storage_path('app/mpdf-tmp'),

];
