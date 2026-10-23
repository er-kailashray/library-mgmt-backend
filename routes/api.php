<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\RenewalController;
use App\Http\Controllers\SeatController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/me', [AuthController::class, 'me']);
    Route::post('/me/update', [AuthController::class, 'updateProfile']);
    Route::post('/me/photo', [AuthController::class, 'uploadPhoto']);
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/batches', [BatchController::class, 'index']);
    Route::post('/batches', [BatchController::class, 'store']);
    Route::get('/seat-matrix', [SeatController::class, 'index']);
    Route::post('/admission', [MemberController::class, 'admission']);

    Route::middleware('library')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'dashboard']);
        Route::get('/collections', [DashboardController::class, 'collections']);
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/members/{id}', [MemberController::class, 'show']);
        Route::post('/members/{id}/update', [MemberController::class, 'update']);
        Route::get('/members/{id}/id-proof/{side}', [MemberController::class, 'idProof']);
        Route::post('/members/{id}/id-proof', [MemberController::class, 'updateIdProof']);
        Route::delete('/members/{id}/id-proof/{side?}', [MemberController::class, 'deleteIdProof']);
        Route::post('/members/{id}/deactivate', [MemberController::class, 'deactivate']);
        Route::post('/members/{id}/remind', [MemberController::class, 'remind']);
        Route::post('/reminders/overdue', [ReminderController::class, 'overdue']);
        Route::get('/renewals/upcoming', [RenewalController::class, 'index']);
        Route::post('/renewals/upcoming/remind', [RenewalController::class, 'remindAll']);
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::get('/library', [LibraryController::class, 'show']);
        Route::post('/library/update', [LibraryController::class, 'update']);
        Route::post('/library/amenities', [LibraryController::class, 'updateAmenities']);
        Route::post('/library/location', [LibraryController::class, 'updateLocation']);
        Route::post('/library/photos', [LibraryController::class, 'uploadPhoto']);
        Route::delete('/library/photos/{id}', [LibraryController::class, 'deletePhoto']);
    });
});
