<?php

use App\Http\Controllers\EventTicketMarkupController;
use Illuminate\Support\Facades\Route;

Route::prefix('markups')->group(function () {
    Route::middleware(['can:markups-view'])->group(function () {
        Route::get('/organizations/{organizationUuid}', [EventTicketMarkupController::class, 'indexByOrganization']);
    });

    Route::middleware(['can:markups-update'])->group(function () {
        Route::patch('/event-tickets/{uuid}', [EventTicketMarkupController::class, 'update']);
    });
});
