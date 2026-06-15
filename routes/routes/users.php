<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->group(function () {
    Route::get('/', [UserController::class, 'index'])
        ->middleware('can:users-view,affiliate-partners-view');
    Route::get('/export', [UserController::class, 'export'])
        ->middleware('can:users-execute');
    Route::post('/', [UserController::class, 'store'])
        ->middleware('can:users-create');
    Route::get('/{uuid}', [UserController::class, 'show'])
        ->middleware('can:users-view');
    Route::put('/{uuid}', [UserController::class, 'update'])
        ->middleware('can:users-update');
    Route::delete('/{uuid}', [UserController::class, 'destroy'])
        ->middleware('can:users-delete');

    // User statistics endpoints
    Route::get('/{uuid}/stats', [UserController::class, 'stats'])
        ->middleware('can:users-view');
    Route::get('/{uuid}/recent-activity', [UserController::class, 'recentActivity'])
        ->middleware('can:users-view');
    Route::get('/{uuid}/tickets', [UserController::class, 'tickets'])
        ->middleware('can:users-view');
    Route::get('/{uuid}/affiliate-partner-stats', [UserController::class, 'affiliatePartnerStats'])
        ->middleware('can:affiliate-partners-view');
    Route::patch('/{uuid}/affiliate-suspend', [UserController::class, 'affiliateSuspend'])
        ->middleware('can:affiliate-partners-update');
    Route::patch('/{uuid}/affiliate-reinstate', [UserController::class, 'affiliateReinstate'])
        ->middleware('can:affiliate-partners-update');
});
