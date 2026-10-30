<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform features (specs/019-transactions-feature-flag)
    |--------------------------------------------------------------------------
    |
    | Platform-wide switches, read by the `feature:{name}` route middleware.
    | They are changed only by environment and deploy: no endpoint, hotel
    | setting or role can turn one on.
    |
    */

    // The transactions ledger (SPEC-005, D11). Off until finance is built:
    // the /transaction endpoints answer 404 and no ledger figure is shown.
    'transactions' => (bool) env('FEATURE_TRANSACTIONS', false),

];
