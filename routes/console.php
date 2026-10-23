<?php

use App\Services\Subscriptions\SubscriptionReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('subscriptions:remind', function (SubscriptionReminderService $reminders) {
    $this->info('Sent '.$reminders->sendDue().' subscription reminder(s).');
})->purpose('SMS owners whose trial/plan is ending, has ended, or has gone read-only');

// Needs the scheduler running on the server: `* * * * * php artisan schedule:run` in cron.
Schedule::command('subscriptions:remind')->dailyAt('09:00')->timezone('Asia/Kolkata')->withoutOverlapping();
