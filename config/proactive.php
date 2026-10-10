<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proactive Concierge messages (SPEC-073)
    |--------------------------------------------------------------------------
    |
    | Each hotel switches proactive messaging on and sets its own quiet hours,
    | caps and timing in hotels.proactive_settings (App\Support\Proactive\
    | ProactiveSettings). These are the platform-wide mechanics.
    |
    */

    // A guest message made only of one of these words opts the guest out of
    // proactive messages and unsolicited offers, or back in. Compared after
    // lowercasing and stripping punctuation, against the whole message, so
    // "stop" inside a sentence never opts anyone out by accident.
    'opt_out_keywords' => ['stop', 'unsubscribe', 'stop all', 'cancel messages', 'توقف', 'إيقاف', 'ايقاف', 'الغاء', 'إلغاء'],

    'opt_in_keywords' => ['start', 'subscribe', 'ابدأ', 'ابدا'],

    // How often EvaluateProactiveTriggersJob runs, in minutes. Matches the
    // schedule in routes/console.php.
    'sweep_minutes' => 15,

    // A guest who wrote this recently is mid-conversation: a proactive
    // message waits rather than interrupting (FR-031).
    'guest_active_minutes' => 30,

    // Send attempts before a message is recorded as failed: the first, and
    // one retry while it is still valid.
    'max_send_attempts' => 2,

    // A claim older than this was left by a worker that died mid-send; the
    // next run may take it over.
    'stale_claim_minutes' => 5,

];
