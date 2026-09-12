<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Foreign Exchange
    |--------------------------------------------------------------------------
    |
    | Providers bill in USD; contracts are agreed in EUR. Costs are STORED in
    | USD, exactly as the provider charged them, and converted only at read
    | time using the rate below — which every report must state alongside the
    | figure it produced.
    |
    | Converting at write time would bake one day's rate into a permanent row
    | and make historical cost silently unreproducible.
    |
    */

    'fx' => [
        // USD per 1 EUR, quoted the way rates are published: 1.09 means one
        // euro buys 1.09 dollars, so EUR = USD / 1.09. Named for the
        // direction it is stored in, because a rate whose direction has to be
        // guessed is a rate that will eventually be applied upside down.
        'usd_per_eur' => (float) env('AI_COST_USD_PER_EUR', 1.09),
        'rate_note' => env('AI_COST_FX_NOTE', 'Month-end reference rate, recorded manually.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Margin Alert
    |--------------------------------------------------------------------------
    |
    | Flag any account whose AI cost passes this share of its recorded
    | contract value. 0.50 = alert once serving the account costs half of what
    | it pays.
    |
    | This ALERTS ONLY and never throttles. A margin problem is a commercial
    | conversation or a misconfiguration; it is not a decision a cron job
    | should make about a paying customer's service.
    |
    */

    'alert_margin_threshold' => (float) env('AI_COST_ALERT_THRESHOLD', 0.50),

    /*
    |--------------------------------------------------------------------------
    | Hard Daily Ceiling (abuse stop)
    |--------------------------------------------------------------------------
    |
    | The one number here that MAY act automatically, in USD per account per
    | day. Its purpose is stopping runaway loops and hostile traffic, not
    | managing margin — so it is set well above any plausible day of normal
    | use, and crossing it means something is wrong rather than busy.
    |
    | It pairs with the per-guest limits in P1-002 A-3: that bounds a single
    | conversation, this bounds an entire account. Set to null to disable.
    |
    */

    'daily_ceiling_usd' => env('AI_COST_DAILY_CEILING_USD', 50.00),

];
