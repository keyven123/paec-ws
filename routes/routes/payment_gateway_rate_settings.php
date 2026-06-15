<?php

use App\Http\Controllers\PaymentGatewayRateSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment-gateway-rate-settings')->group(function () {
    Route::middleware(['can:organizations-view'])
        ->get('/', [PaymentGatewayRateSettingController::class, 'show']);
    Route::middleware(['can:organizations-update'])
        ->put('/', [PaymentGatewayRateSettingController::class, 'update']);
});
