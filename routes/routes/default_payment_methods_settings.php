<?php

use App\Http\Controllers\DefaultPaymentMethodsSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('default-payment-methods-settings')->group(function () {
    Route::middleware(['can:payment-methods-view'])
        ->get('/', [DefaultPaymentMethodsSettingController::class, 'show']);
    Route::middleware(['can:payment-methods-update'])
        ->put('/', [DefaultPaymentMethodsSettingController::class, 'update']);
});
