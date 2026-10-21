<?php

use App\Http\Controllers\CareerPositionController;
use Illuminate\Support\Facades\Route;

Route::prefix('careers')->group(function () {
    Route::middleware(['can:cms-view'])->group(function () {
        Route::get('/positions', [CareerPositionController::class, 'index']);
        Route::get('/positions/{uuid}', [CareerPositionController::class, 'show']);
        Route::get('/applications', [CareerPositionController::class, 'indexApplications']);
    });

    Route::middleware(['can:cms-update'])->group(function () {
        Route::put('/applications/{uuid}/status', [CareerPositionController::class, 'updateApplicationStatus']);
    });

    Route::middleware(['can:cms-create'])->group(function () {
        Route::post('/positions', [CareerPositionController::class, 'store']);
    });

    Route::middleware(['can:cms-update'])->group(function () {
        Route::put('/positions/{uuid}', [CareerPositionController::class, 'update']);
    });

    Route::middleware(['can:cms-delete'])->group(function () {
        Route::delete('/positions/{uuid}', [CareerPositionController::class, 'destroy']);
    });
});
