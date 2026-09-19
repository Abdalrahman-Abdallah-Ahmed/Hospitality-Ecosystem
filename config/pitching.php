<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conversational pitching
    |--------------------------------------------------------------------------
    |
    | Whether the WhatsApp concierge may suggest an activity to a guest who
    | has opened the door to one. Every rule below is enforced in code before
    | the concierge runs; see App\Services\Pitching\PitchEligibilityService.
    |
    | Ships off. Turn it on for a pilot hotel only once the owner decisions in
    | the pitching plan (§15) are recorded. While it is off, every guest turn
    | still gets a decision row saying so, and no classifier call is made.
    |
    */

    'enabled' => (bool) env('PITCHING_ENABLED', false),

    // Unsolicited pitches per stay. A guest who explicitly asks for a
    // suggestion is not being pushed, so explicit requests do not count.
    'max_unsolicited_per_stay' => (int) env('PITCHING_MAX_PER_STAY', 1),

    'shortlist_size' => 3,

    // Pitch guests whose stay has not started yet. Off: capacity and clash
    // checks are about dates they are not yet present for.
    'allow_pre_arrival' => false,

    'complaint' => [
        // An escalation to a human blocks pitching for the rest of the stay.
        // False = escalations do not block at all.
        'escalation_blocks_rest_of_stay' => true,

        // An open service request blocks pitching while it is younger than this.
        'open_service_request_lookback_hours' => 24,

        // Earlier messages the classifier sees alongside the current one.
        'classifier_history_messages' => 4,
    ],

    // Bumped whenever a gate or ranking rule changes. Written into every
    // decision row, so outcomes can be compared across rule versions.
    'rules_version' => '1.0',

];
