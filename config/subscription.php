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

    // Only a fallback: the trial length is set in the admin panel (Settings) and stored in app_settings.
    'trial_days' => env('SUBSCRIPTION_TRIAL_DAYS', 60),

    'grace_days' => env('SUBSCRIPTION_GRACE_DAYS', 3),

];
