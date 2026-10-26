<?php

return [

    /*
    | The secret the full health check (GET /api/health) requires in the
    | X-Health-Token header. Held by the deploy workflow and the uptime
    | monitor. Empty means the full check refuses every caller.
    */
    'token' => env('HEALTH_CHECK_TOKEN'),

];
