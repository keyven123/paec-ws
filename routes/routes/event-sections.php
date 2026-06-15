<?php

use App\Http\Controllers\EventSectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('event-sections')->group(function () {
    Route::middleware(['can:event-sections-view'])->group(function () {
        Route::get('/', [EventSectionController::class, 'index']);
        Route::get('/{uuid}', [EventSectionController::class, 'show']);
    });

    Route::middleware(['can:event-sections-create'])->group(function () {
        Route::post('/', [EventSectionController::class, 'store']);
    });

    Route::middleware(['can:event-sections-update'])->group(function () {
        Route::put('/{uuid}', [EventSectionController::class, 'update']);
    });

    Route::middleware(['can:event-sections-delete'])->group(function () {
        Route::delete('/{uuid}', [EventSectionController::class, 'destroy']);
    });
});
