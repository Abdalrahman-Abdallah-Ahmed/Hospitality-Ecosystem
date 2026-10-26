<?php

return [

    /*
    | Platform alerts: one address that hears about lane waits, repeated job
    | failures and AI spend ceilings. Empty means alerts are only logged.
    */
    'alert_email' => env('OPS_ALERT_EMAIL'),

    // A job class failing this many times inside the window raises one alert.
    'job_failure_threshold' => 3,
    'job_failure_window_minutes' => 15,

    // A lane past its wait limit alerts at most once per this many minutes.
    'lane_alert_cooldown_minutes' => 15,

];
