<?php

use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('uploads')->group(function () {
    Route::get('/{uuid}', [UploadController::class, 'show'])
        ->middleware('can:uploads-view');
    Route::post('/global', [UploadController::class, 'globalUpload'])
        ->middleware('can:uploads-create');
    Route::post('/', [UploadController::class, 'store'])
        ->middleware('can:uploads-create');
    Route::delete('/{uuid}', [UploadController::class, 'destroy'])
        ->middleware('can:uploads-delete');
});
