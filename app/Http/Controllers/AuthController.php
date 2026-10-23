<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\OtpVerification;
use App\Models\Seat;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /** Seconds an owner must wait before another OTP can be sent to the same number. */
    public const OTP_RESEND_COOLDOWN = 30;

    public function register(Request $request, SubscriptionService $subscriptions)
    {
        $request->validate([
            'name' => 'required|string',
            // An unverified number can register again (e.g. the owner left the OTP screen),
            // which refreshes the pending account and sends a new code.
            'phone' => [
                'required',
                'string',
                'regex:/^[6-9]\d{9}$/',
                Rule::unique('users', 'phone')->whereNotNull('phone_verified_at'),
            ],
            'password' => 'required|string|min:6',
            'library_name' => 'required|string',
            'total_seats' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $user = User::where('phone', $request->phone)->first();

            if ($user) {
                $user->update([
                    'name' => $request->name,
                    'password' => bcrypt($request->password),
                ]);

                $library = $user->libraries()->first();
                $library->update(['library_name' => $request->library_name]);

                // Nobody can sign in to an unverified account, so its seats hold no allocations yet.
                if ((int) $library->total_seats !== (int) $request->total_seats) {
                    $library->seats()->delete();
                    $library->update(['total_seats' => $request->total_seats]);
                    $this->createSeats($library, (int) $request->total_seats);
                }
            } else {
                $user = User::create([
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'password' => bcrypt($request->password),
                ]);

                $library = Library::create([
                    'user_id' => $user->id,
                    'library_name' => $request->library_name,
                    'total_seats' => $request->total_seats,
                ]);

                $this->createSeats($library, (int) $request->total_seats);

                // Every new library starts on a free trial.
                $subscriptions->startTrial($library);
            }

            $this->issueOtp($user, 'register');

            DB::commit();

            return response()->json([
                'message' => 'OTP Sent',
            ]);
        } catch (\Exception $e) {
            Log::error($e);
            DB::rollBack();

            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|regex:/^[6-9]\d{9}$/',
            'otp' => 'required|digits:6',
            'type' => 'required|in:register,forgot_password,login',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $otpRecord = OtpVerification::where('user_id', $user->id)
            ->where('type', $request->type)
            ->where('is_verified', false)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otpRecord) {
            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        if ($otpRecord->attempts >= OtpVerification::MAX_ATTEMPTS) {
            return response()->json(['message' => 'Too many attempts. Please request a new OTP'], 429);
        }

        if (! Hash::check($request->otp, $otpRecord->otp)) {
            $otpRecord->increment('attempts');

            return response()->json(['message' => 'Invalid or expired OTP'], 400);
        }

        $otpRecord->update(['is_verified' => true]);

        // Any OTP sent to the phone proves the owner holds the number.
        if (! $user->hasVerifiedPhone()) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        // send token if type is login or register for login flow
        if (in_array($request->type, ['login', 'register'])) {
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'OTP verified successfully',
                'token' => $token,
                'user' => $user,
            ]);
        }

        return response()->json(['message' => 'OTP verified successfully']);
    }

    public function resendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|regex:/^[6-9]\d{9}$/',
            'type' => 'required|in:register',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->hasVerifiedPhone()) {
            return response()->json(['message' => 'This number is already verified. Please sign in.'], 422);
        }

        $wait = $this->otpCooldownRemaining($user, $request->type);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before requesting a new code.",
                'retry_after' => $wait,
            ], 429);
        }

        $this->issueOtp($user, $request->type);

        return response()->json([
            'message' => 'OTP Sent',
            'retry_after' => self::OTP_RESEND_COOLDOWN,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'password' => 'required',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        if (! $user->hasVerifiedPhone()) {
            // Keep the code already sent if it is still fresh, otherwise send a new one.
            if ($this->otpCooldownRemaining($user, 'register') === 0) {
                $this->issueOtp($user, 'register');
            }

            return response()->json([
                'message' => 'Please verify your phone number to continue.',
                'requires_verification' => true,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    private function createSeats(Library $library, int $count): void
    {
        $seats = [];
        for ($i = 1; $i <= $count; $i++) {
            $seats[] = [
                'library_id' => $library->id,
                'seat_number' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Seat::insert($seats);
    }

    /**
     * Creates a fresh OTP for the user. Verification always checks the newest one,
     * so earlier unused codes stop working.
     */
    private function issueOtp(User $user, string $type): void
    {
        $otp = random_int(100000, 999999);

        OtpVerification::create([
            'user_id' => $user->id,
            'otp' => Hash::make($otp),
            'type' => $type,
            'expires_at' => now()->addMinutes(5),
            'channel' => 'sms',
        ]);

        // SMS delivery is disabled for now; the OTP is only written to the log
        Log::info('Registration OTP generated', [
            'to' => '+91'.$user->phone,
            'otp' => $otp,
        ]);
    }

    private function otpCooldownRemaining(User $user, string $type): int
    {
        $last = OtpVerification::where('user_id', $user->id)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (! $last) {
            return 0;
        }

        $elapsed = (int) $last->created_at->diffInSeconds(now(), true);

        return max(0, self::OTP_RESEND_COOLDOWN - $elapsed);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out succcessfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => [
                'required',
                'regex:/^[6-9]\d{9}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated',
            'user' => $user,
        ]);
    }

    public function uploadPhoto(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $path = $request->file('photo')->store("profile-photos/{$user->id}", 'public');

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
        }

        $user->update(['profile_photo' => $path]);

        return response()->json([
            'message' => 'Profile photo updated',
            'user' => $user,
        ]);
    }
}
