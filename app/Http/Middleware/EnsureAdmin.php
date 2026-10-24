<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Admin panel pages: only signed-in users with the admin role. Library owners are signed out. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || $request->user()->role !== 'admin') {
            Auth::guard('web')->logout();

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
