<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document storage
    |--------------------------------------------------------------------------
    |
    | Uploaded knowledge documents live on a private disk and are only ever
    | served through the authorised download endpoint. The pipeline reads the
    | stored file and never writes to it.
    |
    */

    'disk' => env('KNOWLEDGE_DISK', 'local'),

    'max_upload_kb' => 20480,

    // Spreadsheet or CSV rows a single document may hold before it is
    // refused as too_many_rows.
    'max_rows' => 20000,

    /*
    |--------------------------------------------------------------------------
    | AI vision (images and scanned PDF pages)
    |--------------------------------------------------------------------------
    |
    | Pages with a text layer are read directly; only images and image-only
    | pages are sent to the vision model. The page cap is checked before any
    | call is made, so a document over it costs nothing.
    |
    */

    'vision' => [
        'provider' => env('KNOWLEDGE_VISION_PROVIDER', 'gemini'),
        'model' => env('KNOWLEDGE_VISION_MODEL'),
        'max_pages' => 50,
        // Attachments are sent inline as base64 (~33% larger), and the
        // provider's inline request limit is about 20 MB.
        'max_file_mb' => 14,
        // Seconds per vision call; must stay below the indexing job's 300.
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | Hotel and global knowledge are searched separately and returned hotel
    | first, so a closer global passage can never push the hotel's own rule
    | out of the result set.
    |
    */

    'search' => [
        'hotel_limit' => 5,
        'global_limit' => 3,
        'min_similarity' => 0.5,
    ],

    // Deleted documents can be restored for this many days, then the purge
    // job removes the row, its passages and its files for good.
    'purge_after_days' => 30,

];
