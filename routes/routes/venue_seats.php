<?php

use App\Http\Controllers\VenueSeatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:admin'])->group(function () {
    Route::get('venue-seats', [VenueSeatController::class, 'index'])
        ->middleware('can:venue-seats-view');

    Route::post('venue-seats', [VenueSeatController::class, 'store'])
        ->middleware('can:venue-seats-create');

    Route::get('venue-seats/{uuid}', [VenueSeatController::class, 'show'])
        ->middleware('can:venue-seats-view');

    Route::put('venue-seats/{uuid}', [VenueSeatController::class, 'update'])
        ->middleware('can:venue-seats-edit');

    Route::delete('venue-seats/{uuid}', [VenueSeatController::class, 'destroy'])
        ->middleware('can:venue-seats-delete');
});
