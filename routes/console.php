<?php

use App\Models\User;
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

Artisan::command('librum:make-admin {phone : 10-digit phone number used to sign in} {--name=Librum Admin}', function (string $phone) {
    if (! preg_match('/^[6-9]\d{9}$/', $phone)) {
        $this->error('Enter a valid 10-digit phone number.');

        return 1;
    }

    $existing = User::where('phone', $phone)->first();
    if ($existing && $existing->role !== 'admin') {
        $this->error('That number belongs to a library owner. Use a different number for the admin.');

        return 1;
    }

    $password = $this->secret('Password (at least 8 characters)');
    if (! $password || strlen($password) < 8 || $password !== $this->secret('Repeat the password')) {
        $this->error('Passwords must match and be at least 8 characters.');

        return 1;
    }

    $admin = User::updateOrCreate(['phone' => $phone], [
        'name' => $this->option('name'),
        'password' => $password,
        'role' => 'admin',
    ]);
    $admin->forceFill(['phone_verified_at' => now()])->save();

    $this->info(($existing ? 'Updated' : 'Created')." admin {$phone}. Sign in at ".url('/admin').'.');
})->purpose("Create a Librum admin panel account, or reset an admin's password");
