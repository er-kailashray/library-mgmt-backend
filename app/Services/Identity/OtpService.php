<?php

namespace App\Services\Identity;

use App\Models\OtpVerification;
use App\Models\User;
use App\Notifications\DTO\MessageDTO;
use App\Notifications\Services\NotificationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Phone codes for every sign-in and verification flow (owner register/login, password reset).
 * 6 digits, 5-minute expiry; only the newest code of a type works, it locks
 * after OtpVerification::MAX_ATTEMPTS wrong guesses, and a new one can be sent every
 * RESEND_COOLDOWN seconds.
 *
 * Texting is off unless OTP_SMS_ENABLED is set; outside production the code is always logged
 * (storage/logs/laravel.log, "OTP generated") so flows can be tried without SMS.
 */
class OtpService
{
    public const RESEND_COOLDOWN = 30;

    private const EXPIRY_MINUTES = 5;

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return bool false only when SMS is on and could not be sent */
    public function issue(User $user, string $type): bool
    {
        $otp = random_int(100000, 999999);

        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make($otp),
            'type' => $type,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            'channel' => 'sms',
        ]);

        if (! app()->isProduction()) {
            Log::info('OTP generated', [
                'type' => $type,
                'to' => '+91'.$user->phone,
                'otp' => $otp,
            ]);
        }

        if (! config('services.otp.sms_enabled')) {
            return true;
        }

        try {
            $this->notifications->send('sms', new MessageDTO(
                to: '+91'.$user->phone,
                message: "{$otp} is your verification code. It expires in ".self::EXPIRY_MINUTES.' minutes. Do not share it with anyone.',
            ));
        } catch (\Throwable $e) {
            Log::warning('OTP SMS failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /** Seconds until another code of this type may be sent (0 = now). */
    public function cooldownRemaining(User $user, string $type): int
    {
        $last = OtpVerification::where('user_id', $user->id)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (! $last) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN - (int) $last->created_at->diffInSeconds(now(), true));
    }

    /**
     * Checks the newest unexpired code of this type and marks it used; a correct code also proves
     * the person holds the phone.
     *
     * @return array{message: string, status: int}|null the error to return, or null when valid
     */
    public function consume(User $user, string $type, string $otp): ?array
    {
        $record = OtpVerification::where('user_id', $user->id)
            ->where('type', $type)
            ->where('is_verified', false)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $record) {
            return ['message' => 'Invalid or expired OTP', 'status' => 400];
        }

        if ($record->attempts >= OtpVerification::MAX_ATTEMPTS) {
            return ['message' => 'Too many attempts. Please request a new OTP', 'status' => 429];
        }

        if (! Hash::check($otp, $record->otp)) {
            $record->increment('attempts');

            return ['message' => 'Invalid or expired OTP', 'status' => 400];
        }

        $record->update(['is_verified' => true]);

        if (! $user->hasVerifiedPhone()) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return null;
    }
}
