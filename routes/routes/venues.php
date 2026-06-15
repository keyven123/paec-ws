<?php

use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Route;

Route::prefix('venues')->group(function () {
    Route::middleware(['can:venues-view'])->group(function () {
        Route::get('/', [VenueController::class, 'index']);
        Route::get('/{uuid}', [VenueController::class, 'show']);
    });

    Route::middleware(['can:venues-create'])->group(function () {
        Route::post('/', [VenueController::class, 'store']);
    });

    Route::middleware(['can:venues-update'])->group(function () {
        Route::put('/{uuid}', [VenueController::class, 'update']);
    });

    Route::middleware(['can:venues-delete'])->group(function () {
        Route::delete('/{uuid}', [VenueController::class, 'destroy']);
    });
});
