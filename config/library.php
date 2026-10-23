<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dues
    |--------------------------------------------------------------------------
    |
    | A member is due once an active seat allocation's renewal_date is reached
    | (on the day itself). These thresholds drive the dashboard's due breakdowns.
    |
    */

    'dues' => [
        // Members overdue by at least this many days are counted as critical
        'critical_after_days' => env('LIBRARY_CRITICAL_DUE_DAYS', 15),

        // Window (in days, from today) for "renewals due this week"
        'upcoming_renewal_window_days' => env('LIBRARY_RENEWAL_WINDOW_DAYS', 7),

        // Overdue by this many billing cycles or more lands in "2+ months overdue"
        'long_overdue_cycles' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'recent_payments_limit' => 5,

        // Most-overdue members named on the dashboard's dues card
        'top_dues_limit' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | Keys must match the payments.payment_mode enum column.
    |
    */

    'payment_modes' => [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'bank_transfer' => 'Bank Transfer',
    ],

    'payment_mode_fallback_label' => 'Other',

];
