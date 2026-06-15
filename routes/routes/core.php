<?php

use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;

// Admin portal routes
Route::middleware(['portal:admin'])->prefix('admin')->group(function () {
    Route::get('dashboard', [PortalController::class, 'adminDashboard']);
});

// Customer portal routes
Route::middleware(['portal:customer'])->prefix('customer')->group(function () {
    Route::get('portal', [PortalController::class, 'customerPortal']);
});

// General permission check route
Route::get('check-permissions', [PortalController::class, 'checkPermissions']);

// Test routes for ability middleware
Route::get('test-dashboard', function () {
    return response()->json(['message' => 'Dashboard access granted!']);
})->middleware('can:dashboard-view');

Route::get('test-users', function () {
    return response()->json(['message' => 'Users access granted!']);
})->middleware('can:users-view');

Route::get('test-invalid', function () {
    return response()->json(['message' => 'This should not be accessible!']);
})->middleware('can:invalid-permission');
