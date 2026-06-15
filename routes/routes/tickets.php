<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'tickets'], function () {
    Route::get('/', [TicketController::class, 'index'])
        ->middleware('can:tickets-view');

    Route::post('/', [TicketController::class, 'store'])
        ->middleware('can:tickets-create');

    Route::get('{uuid}', [TicketController::class, 'show'])
        ->middleware('can:tickets-view');

    Route::put('{uuid}', [TicketController::class, 'update'])
        ->middleware('can:tickets-update');

    Route::put('{uuid}/cancel', [TicketController::class, 'cancel'])
        ->middleware('can:tickets-update');

    Route::put('{uuid}/upgrade', [TicketController::class, 'upgrade'])
        ->middleware('can:tickets-update');

    Route::delete('{uuid}', [TicketController::class, 'destroy'])
        ->middleware('can:tickets-delete');

    Route::post('add-to-user', [TicketController::class, 'addTicketToUser'])
        ->middleware('can:tickets-add');

    // Additional ticket-specific routes
    Route::post('{uuid}/use', [TicketController::class, 'markAsUsed'])
        ->middleware('can:tickets-update');

    Route::post('{uuid}/transfer', [TicketController::class, 'transfer'])
        ->middleware('can:tickets-update');

    // Scanner routes
    Route::get('qr-code/details', [TicketController::class, 'getTicketsDetailByQrCode'])
        ->middleware('can:event-scanner-create');

    Route::post('{uuid}/confirm-entry', [TicketController::class, 'confirmEntry'])
        ->middleware('can:event-scanner-create');
});
