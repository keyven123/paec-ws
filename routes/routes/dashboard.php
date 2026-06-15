<?php

use App\Http\Controllers\AdminDashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/dashboard')->group(function () {
    Route::get('/recent-activities', [AdminDashboardController::class, 'recentActivities'])->middleware('can:dashboard-view');
    Route::get('/stats', [AdminDashboardController::class, 'dashboardStats'])->middleware('can:dashboard-view');
});
