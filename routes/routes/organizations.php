<?php

use App\Http\Controllers\AdminOrganizationAccountingController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::prefix('organizations')->group(function () {
    Route::middleware(['can:organizations-view'])->group(function () {
        Route::get('/', [OrganizationController::class, 'index']);
        Route::get('/stats', [OrganizationController::class, 'stats']);
        Route::get('/{uuid}', [OrganizationController::class, 'show']);
        Route::get('/{uuid}/accounting/summary', [AdminOrganizationAccountingController::class, 'summary']);
        Route::get('/{uuid}/accounting/remittance-buckets', [AdminOrganizationAccountingController::class, 'remittanceBuckets']);
        Route::get('/{uuid}/accounting/transactions', [AdminOrganizationAccountingController::class, 'transactions']);
        Route::get('/{uuid}/accounting/payout-requests', [AdminOrganizationAccountingController::class, 'payoutRequests']);
    });

    Route::middleware(['can:organizations-create'])->group(function () {
        Route::post('/', [OrganizationController::class, 'store']);
        Route::patch('/{uuid}/approve', [OrganizationController::class, 'approve']);
        Route::patch('/{uuid}/reject', [OrganizationController::class, 'reject']);
        Route::patch('/{uuid}/onboard', [OrganizationController::class, 'onboard']);
        Route::patch('/{uuid}/send-invite', [OrganizationController::class, 'sendInvitation']);
    });

    Route::middleware(['can:organizations-update'])->group(function () {
        Route::put('/{uuid}', [OrganizationController::class, 'update']);
    });

    Route::middleware(['can:commissions-update'])->group(function () {
        Route::put('/{uuid}/commission-percentage', [OrganizationController::class, 'updateCommissionPercentage']);
    });

    Route::middleware(['can:payment-methods-update'])->group(function () {
        Route::put('/{uuid}/payment-methods', [OrganizationController::class, 'updatePaymentMethods']);
    });

    Route::middleware(['can:organizations-delete'])->group(function () {
        Route::delete('/{uuid}', [OrganizationController::class, 'destroy']);
    });
});
