<?php

use App\Http\Controllers\PromoCodeController;
use Illuminate\Support\Facades\Route;

Route::prefix('promo-codes')->group(function () {
    Route::middleware(['can:promo-codes-view'])->group(function () {
        Route::get('/', [PromoCodeController::class, 'index']);
        Route::get('/{uuid}', [PromoCodeController::class, 'show']);
    });

    Route::middleware(['can:promo-codes-create'])->group(function () {
        Route::post('/', [PromoCodeController::class, 'store']);
    });

    Route::middleware(['can:promo-codes-update'])->group(function () {
        Route::put('/{uuid}', [PromoCodeController::class, 'update']);
    });

    Route::middleware(['can:promo-codes-delete'])->group(function () {
        Route::delete('/{uuid}', [PromoCodeController::class, 'destroy']);
    });
});
