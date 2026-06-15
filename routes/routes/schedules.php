<?php

use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('schedules')->group(function () {
    Route::middleware(['can:schedules-view'])->group(function () {
        Route::get('/', [ScheduleController::class, 'index']);
        Route::get('/{uuid}', [ScheduleController::class, 'show']);
    });

    Route::middleware(['can:schedules-create'])->group(function () {
        Route::post('/', [ScheduleController::class, 'store']);
    });

    Route::middleware(['can:schedules-update'])->group(function () {
        Route::put('/{uuid}', [ScheduleController::class, 'update']);
    });

    Route::middleware(['can:schedules-delete'])->group(function () {
        Route::delete('/{uuid}', [ScheduleController::class, 'destroy']);
    });
});
