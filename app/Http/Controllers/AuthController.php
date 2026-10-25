<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Seat;
use App\Models\User;
use App\Services\Identity\OtpService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otps) {}

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
            'total_seats' => 'required|integer|min:1|max:500',
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

            $this->otps->issue($user, 'register');

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

        if ($error = $this->otps->consume($user, $request->type, $request->otp)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        // send token if type is login or register for login flow
        if (in_array($request->type, ['login', 'register'])) {
            $token = $this->issueToken($user);

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

        $wait = $this->otps->cooldownRemaining($user, $request->type);

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before requesting a new code.",
                'retry_after' => $wait,
            ], 429);
        }

        $this->otps->issue($user, $request->type);

        return response()->json([
            'message' => 'OTP Sent',
            'retry_after' => OtpService::RESEND_COOLDOWN,
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
            if ($this->otps->cooldownRemaining($user, 'register') === 0) {
                $this->otps->issue($user, 'register');
            }

            return response()->json([
                'message' => 'Please verify your phone number to continue.',
                'requires_verification' => true,
            ], 403);
        }

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
        ]);
    }

    /**
     * Sends a password-reset code. Also serves as "resend", subject to the same cooldown.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|regex:/^[6-9]\d{9}$/',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'No account found with this number'], 404);
        }

        $wait = $this->otps->cooldownRemaining($user, 'forgot_password');

        if ($wait > 0) {
            return response()->json([
                'message' => "Please wait {$wait} seconds before requesting a new code.",
                'retry_after' => $wait,
            ], 429);
        }

        $this->otps->issue($user, 'forgot_password');

        return response()->json([
            'message' => 'OTP Sent',
            'retry_after' => OtpService::RESEND_COOLDOWN,
        ]);
    }

    /**
     * Sets a new password using the code from forgotPassword, and signs out every device.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|regex:/^[6-9]\d{9}$/',
            'otp' => 'required|digits:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($error = $this->otps->consume($user, 'forgot_password', $request->otp)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $user->update(['password' => bcrypt($request->password)]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully. Please sign in.']);
    }

    /**
     * Changes the signed-in owner's password; other devices are signed out, this one stays in.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'errors' => ['current_password' => ['Current password is incorrect']],
            ], 422);
        }

        $user->update(['password' => bcrypt($request->password)]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed successfully']);
    }

    private function issueToken(User $user): string
    {
        return $user->createToken('auth_token')->plainTextToken;
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
