<?php

use App\Http\Controllers\MerchantCommissionSettingController;
use Illuminate\Support\Facades\Route;

Route::prefix('merchant-commission-settings')->group(function () {
    Route::middleware(['can:commissions-view'])
        ->get('/', [MerchantCommissionSettingController::class, 'show']);
    Route::middleware(['can:commissions-update'])
        ->put('/', [MerchantCommissionSettingController::class, 'update']);
});
