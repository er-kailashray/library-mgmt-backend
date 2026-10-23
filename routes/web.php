<?php

use App\Http\Controllers\SubscriptionPaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Subscription checkout opened from the app's in-app browser. Signed, short-lived URLs only.
Route::middleware('signed')->group(function () {
    Route::get('/subscription/pay/{payment}', [SubscriptionPaymentPageController::class, 'show'])->name('subscription.pay');
    Route::post('/subscription/pay/{payment}/callback', [SubscriptionPaymentPageController::class, 'callback'])->name('subscription.pay.callback');
});
