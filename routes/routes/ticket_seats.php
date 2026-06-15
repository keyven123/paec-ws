<?php

use App\Http\Controllers\TicketSeatController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:admin'])->group(function () {
    Route::get('ticket-seats', [TicketSeatController::class, 'index'])
        ->middleware('can:ticket-seats-view');

    Route::post('ticket-seats', [TicketSeatController::class, 'store'])
        ->middleware('can:ticket-seats-create');

    Route::get('ticket-seats/{uuid}', [TicketSeatController::class, 'show'])
        ->middleware('can:ticket-seats-view');

    Route::put('ticket-seats/{uuid}', [TicketSeatController::class, 'update'])
        ->middleware('can:ticket-seats-update');

    Route::delete('ticket-seats/{uuid}', [TicketSeatController::class, 'destroy'])
        ->middleware('can:ticket-seats-delete');
});
