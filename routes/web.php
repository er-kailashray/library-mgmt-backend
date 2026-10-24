<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\LibraryController as AdminLibraryController;
use App\Http\Controllers\Admin\SettingsController;
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

// Librum admin panel (the Librum team, not library owners).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', [AdminLibraryController::class, 'index'])->name('libraries');
        Route::get('/libraries/{library}', [AdminLibraryController::class, 'show'])->name('libraries.show');
        Route::post('/libraries/{library}/subscription', [AdminLibraryController::class, 'adjust'])->name('libraries.adjust');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings/trial', [SettingsController::class, 'updateTrial'])->name('settings.trial');
        Route::post('/settings/plans/{plan}', [SettingsController::class, 'updatePlan'])->name('settings.plan');
        Route::get('/activity', [ActivityController::class, 'index'])->name('activity');
    });
});
