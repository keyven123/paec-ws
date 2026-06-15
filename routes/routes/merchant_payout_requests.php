<?php

use App\Http\Controllers\AdminMerchantPayoutRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('merchant-payout-requests')->group(function () {
    Route::middleware(['can:organizations-view'])->group(function () {
        Route::get('/', [AdminMerchantPayoutRequestController::class, 'index']);
    });
    Route::middleware(['can:organizations-update'])->group(function () {
        Route::patch('/{uuid}/approve', [AdminMerchantPayoutRequestController::class, 'approve']);
        Route::patch('/{uuid}/decline', [AdminMerchantPayoutRequestController::class, 'decline']);
    });
});
