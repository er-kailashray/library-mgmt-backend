<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Free trial & grace
    |--------------------------------------------------------------------------
    |
    | Every new library gets a free trial. After a subscription (or the trial)
    | ends there's a short grace period with full access before the library
    | drops to read-only.
    |
    */

    'trial_months' => env('SUBSCRIPTION_TRIAL_MONTHS', 2),

    'grace_days' => env('SUBSCRIPTION_GRACE_DAYS', 3),

];
