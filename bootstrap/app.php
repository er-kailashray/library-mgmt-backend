<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Razorpay posts the checkout result from its own site; that route is protected by its
        // signed URL and the payment signature instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['subscription/pay/*/callback']);

        $middleware->alias([
            'library' => \App\Http\Middleware\ResolveLibrary::class,
            'subscription' => \App\Http\Middleware\EnsureSubscriptionAllowsWrites::class,
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
        ]);

        // Signed-out visitors to the admin panel go to its sign-in page (the API answers 401 instead).
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
