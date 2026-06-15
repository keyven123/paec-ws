<?php

use App\Http\Controllers\EventTicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('event-tickets')->group(function () {
    Route::middleware(['can:event-tickets-view'])->group(function () {
        Route::get('/', [EventTicketController::class, 'index']);
        Route::get('/{uuid}', [EventTicketController::class, 'show']);
    });

    Route::middleware(['can:event-tickets-create'])->group(function () {
        Route::post('/bulk', [EventTicketController::class, 'bulkStore']);
        Route::post('/duplicate', [EventTicketController::class, 'duplicate']);
        Route::post('/', [EventTicketController::class, 'store']);
    });

    Route::middleware(['can:event-tickets-update'])->group(function () {
        Route::put('/{uuid}', [EventTicketController::class, 'update']);
    });

    Route::middleware(['can:event-tickets-delete'])->group(function () {
        Route::delete('/{uuid}', [EventTicketController::class, 'destroy']);
    });
});
