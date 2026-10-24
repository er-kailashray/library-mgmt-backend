<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Admin panel sign-in: phone and password, admins only. Admins are created with `php artisan librum:make-admin`. */
class AuthController extends Controller
{
    public function showLogin()
    {
        return Auth::check() && Auth::user()->role === 'admin' ? redirect()->route('admin.libraries') : view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials) || Auth::user()->role !== 'admin') {
            Auth::guard('web')->logout();

            return back()->withInput(['phone' => $credentials['phone']])->withErrors(['phone' => 'Wrong phone number or password.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('admin.libraries'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
