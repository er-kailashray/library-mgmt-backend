<?php

return [
    // Shown on the website (contact page, footer). Set in .env before launch.
    'support_email' => env('SUPPORT_EMAIL', 'support@librispace.com'),
    'support_phone' => env('SUPPORT_PHONE'),
    'business_name' => env('BUSINESS_NAME', 'KailashRay'),
    'business_address' => env('BUSINESS_ADDRESS'),
    'business_jurisdiction' => env('BUSINESS_JURISDICTION'),
    // Printed on plan invoices when set.
    'business_gstin' => env('BUSINESS_GSTIN'),

    // Store pages for the website's download badges. The Play link works once the app is published.
    'play_store_url' => env('PLAY_STORE_URL', 'https://play.google.com/store/apps/details?id=com.kailashray.librispace'),
    'app_store_url' => env('APP_STORE_URL'),

    // Label shown on a plan in the app and on the website, by its length in months.
    'plan_badges' => [6 => 'Most popular', 12 => 'Best value'],

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
