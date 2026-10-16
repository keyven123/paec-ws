<?php

use App\Http\Controllers\AuthAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->group(function () {
    // Protected routes (require authentication)
    Route::get('/me', [AuthAdminController::class, 'me']);
    Route::post('/refresh', [AuthAdminController::class, 'refresh']);
    Route::post('/logout', [AuthAdminController::class, 'logout']);
    Route::post('/change-password', [AuthAdminController::class, 'changePassword']);
    Route::put('/profile', [AuthAdminController::class, 'updateProfile']);
    Route::get('/dashboard-stats', [AuthAdminController::class, 'dashboardStats'])
        ->middleware('can:dashboard-view');
});
