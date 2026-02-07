<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
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

            for ($i = 1; $i <= $request->total_seats; $i++) {
                Seat::create([
                    'library_id' => $library->id,
                    'seat_number' => $i
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            DB::commit();

            return response()->json([
                'user' => $user,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Something went wrong'], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'password' => 'required'
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid Credentials'], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out succcessfully']);
    }

    function me(Request $request)
    {
        return response()->json($request->user());
    }
}
