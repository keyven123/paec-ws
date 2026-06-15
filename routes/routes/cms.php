<?php

use App\Http\Controllers\CmsController;
use Illuminate\Support\Facades\Route;

Route::prefix('cms')->group(function () {
    Route::middleware(['can:cms-view'])->group(function () {
        Route::get('/pages', [CmsController::class, 'indexPages']);
        Route::get('/pages/{uuid}', [CmsController::class, 'showPage']);
        Route::get('/footer', [CmsController::class, 'showFooterSettings']);
    });

    Route::middleware(['can:cms-create'])->group(function () {
        Route::post('/pages', [CmsController::class, 'storePage']);
    });

    Route::middleware(['can:cms-update'])->group(function () {
        Route::put('/pages/{uuid}', [CmsController::class, 'updatePage']);
        Route::put('/footer', [CmsController::class, 'updateFooterSettings']);
    });

    Route::middleware(['can:cms-delete'])->group(function () {
        Route::delete('/pages/{uuid}', [CmsController::class, 'destroyPage']);
    });
});
