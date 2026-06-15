<?php

use App\Http\Controllers\CategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('categories')->group(function () {
    Route::middleware(['can:categories-view'])->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{uuid}', [CategoryController::class, 'show']);
    });

    Route::middleware(['can:categories-create'])->group(function () {
        Route::post('/', [CategoryController::class, 'store']);
    });

    Route::middleware(['can:categories-update'])->group(function () {
        Route::put('/{uuid}', [CategoryController::class, 'update']);
    });

    Route::middleware(['can:categories-delete'])->group(function () {
        Route::delete('/{uuid}', [CategoryController::class, 'destroy']);
    });
});
