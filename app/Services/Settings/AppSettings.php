<?php

namespace App\Services\Settings;

use App\Models\AppSetting;

/**
 * Settings the Librum team changes from the admin panel (no deploy needed). Falls back to
 * config when a setting has never been saved.
 */
class AppSettings
{
    public const TRIAL_DAYS = 'trial_days';

    public const TRIAL_DAYS_MIN = 1;

    public const TRIAL_DAYS_MAX = 365;

    public function get(string $key, ?string $default = null): ?string
    {
        return AppSetting::find($key)?->value ?? $default;
    }

    public function set(string $key, string $value): void
    {
        AppSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Free trial length for libraries that sign up from now on. Existing trials keep theirs. */
    public function trialDays(): int
    {
        return (int) $this->get(self::TRIAL_DAYS, (string) config('subscription.trial_days'));
    }
}
