<?php

use App\Http\Controllers\ScheduleTimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('schedule-times')->group(function () {
    Route::middleware(['can:schedule-times-view'])->group(function () {
        Route::get('/', [ScheduleTimeController::class, 'index']);
        Route::get('/{uuid}', [ScheduleTimeController::class, 'show']);
    });

    Route::middleware(['can:schedule-times-create'])->group(function () {
        Route::post('/', [ScheduleTimeController::class, 'store']);
    });

    Route::middleware(['can:schedule-times-update'])->group(function () {
        Route::put('/{uuid}', [ScheduleTimeController::class, 'update']);
    });

    Route::middleware(['can:schedule-times-delete'])->group(function () {
        Route::delete('/{uuid}', [ScheduleTimeController::class, 'destroy']);
    });
});
