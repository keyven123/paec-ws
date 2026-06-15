<?php

use App\Http\Controllers\AdminAffiliatePayoutRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('affiliate-payout-requests')->group(function () {
    Route::middleware(['can:affiliate-payouts-view'])->group(function () {
        Route::get('/', [AdminAffiliatePayoutRequestController::class, 'index']);
    });
    Route::middleware(['can:affiliate-payouts-update'])->group(function () {
        Route::patch('/{uuid}/approve', [AdminAffiliatePayoutRequestController::class, 'approve']);
        Route::patch('/{uuid}/decline', [AdminAffiliatePayoutRequestController::class, 'decline']);
    });
});
