<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attribution
    |--------------------------------------------------------------------------
    |
    | How the nightly matching job links a booking back to a recommendation
    | when nothing observed the link directly. The window is a starting
    | hypothesis, not a fact — only real data can settle whether 24, 48 or 72
    | hours is right. That is exactly why the value in force is written into
    | every inferred row's `context`, alongside the matcher version: someone
    | will want to compare windows later, and they must be able to tell which
    | rows were produced under which rule.
    |
    */

    'attribution' => [
        'window_hours' => (int) env('RECOMMENDATION_ATTRIBUTION_WINDOW_HOURS', 72),
        'matcher_version' => '1.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversational capture
    |--------------------------------------------------------------------------
    |
    | The agent classifies the guest's response at the end of an exchange. When
    | it is less sure than this, the outcome is recorded as "delivered" rather
    | than as an acceptance or a refusal.
    |
    | An ambiguous "we'll see" is not a yes. Politeness reads as agreement in
    | several of the guest languages here far more readily than it does in
    | English, and defaulting ambiguity to acceptance inflates every number
    | downstream.
    |
    */

    'conversational' => [
        'confidence_threshold' => (float) env('RECOMMENDATION_CONFIDENCE_THRESHOLD', 0.7),
    ],

];
