<?php

use App\Http\Controllers\PaymentGatewayController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment-gateways')->group(function () {
    // Activity editors pick gateways in the activity wizard without needing the Payments module.
    Route::middleware(['can:payments-view,events-create,events-update,fun-create,fun-update'])
        ->get('/options', [PaymentGatewayController::class, 'options']);

    Route::middleware(['can:payments-view'])->group(function () {
        Route::get('/', [PaymentGatewayController::class, 'index']);
        Route::get('/{uuid}', [PaymentGatewayController::class, 'show']);
    });

    Route::middleware(['can:payments-create'])
        ->post('/', [PaymentGatewayController::class, 'store']);

    Route::middleware(['can:payments-update'])
        ->put('/{uuid}', [PaymentGatewayController::class, 'update']);

    Route::middleware(['can:payments-delete'])
        ->delete('/{uuid}', [PaymentGatewayController::class, 'destroy']);
});
