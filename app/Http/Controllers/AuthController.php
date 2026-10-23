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
    public function register(Request $request, SubscriptionService $subscriptions)
    {
        $request->validate([
            'name' => 'required|string',
            'phone' => 'required|string|unique:users,phone|regex:/^[6-9]\d{9}$/',
            'password' => 'required|string|min:6',
            'library_name' => 'required|string',
            'total_seats' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
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

            $seats = [];
            for ($i = 1; $i <= $request->total_seats; $i++) {
                $seats[] = [
                    'library_id' => $library->id,
                    'seat_number' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            Seat::insert($seats);

            // Every new library starts on a free trial.
            $subscriptions->startTrial($library);

            // Send OTP to the user's phone number
            $otp = random_int(100000, 999999);

            OtpVerification::create([
                'user_id' => $user->id,
                'otp' => Hash::make($otp),
                'type' => 'register',
                'expires_at' => now()->addMinutes(5),
                'channel' => 'sms',
            ]);

            // SMS delivery is disabled for now; the OTP is only written to the log
            Log::info('Registration OTP generated', [
                'to' => '+91'.$request->phone,
                'otp' => $otp,
            ]);

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
            ->latest()
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

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
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
