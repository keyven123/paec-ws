<?php

use App\Http\Controllers\AdminCommissionLedgerController;
use App\Http\Controllers\AdminPlatformPnLController;
use App\Http\Controllers\AdminOperatorConsoleController;
use App\Http\Controllers\AdminTransactionPnLController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/finance')->group(function () {
    Route::get('/platform-pnl', [AdminPlatformPnLController::class, 'show'])
        ->middleware('can:finance-view');
    Route::get('/commission-ledger', [AdminCommissionLedgerController::class, 'show'])
        ->middleware('can:finance-view');
    Route::get('/operator-console', [AdminOperatorConsoleController::class, 'show'])
        ->middleware('can:finance-view');
    Route::get('/operator-console/events', [AdminOperatorConsoleController::class, 'remittanceEvents'])
        ->middleware('can:finance-view');
    Route::get('/operator-console/remittances', [AdminOperatorConsoleController::class, 'remittances'])
        ->middleware('can:finance-view');
    Route::get('/operator-console/pending-payouts', [AdminOperatorConsoleController::class, 'pendingPayouts'])
        ->middleware('can:finance-view');
    Route::get('/operator-console/payout-requests', [AdminOperatorConsoleController::class, 'payoutRequests'])
        ->middleware('can:finance-view');
    Route::post('/operator-console/remittances', [AdminOperatorConsoleController::class, 'storeRemittance'])
        ->middleware('can:finance-view');
    Route::patch('/operator-console/remittances/{uuid}/void', [AdminOperatorConsoleController::class, 'voidRemittance'])
        ->middleware('can:finance-view');
    Route::get('/transaction-pnl', [AdminTransactionPnLController::class, 'index'])
        ->middleware('can:finance-view');
});
