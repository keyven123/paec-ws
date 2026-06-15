<?php

use App\Http\Controllers\TicketCouponController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'ticket-coupons'], function () {
    Route::get('/', [TicketCouponController::class, 'index'])
        ->middleware('can:event-scanner-view');

    // Scanner routes
    Route::get('qr-code/details', [TicketCouponController::class, 'getByQrCode'])
        ->middleware('can:event-scanner-create');

    Route::post('{uuid}/confirm-claimed', [TicketCouponController::class, 'confirmClaimed'])
        ->middleware('can:event-scanner-create');
});
