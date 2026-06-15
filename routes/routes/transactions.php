<?php

use App\Http\Controllers\Customer\TempTransactionController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'transactions', 'middleware' => 'auth:admin'], function () {
    Route::get('/', [TransactionController::class, 'index'])
        ->middleware('can:transactions-view');

    Route::post('/', [TransactionController::class, 'store'])
        ->middleware('can:transactions-create');

    Route::group(['prefix' => '{uuid}'], function () {
        Route::get('/', [TransactionController::class, 'show'])
            ->middleware('can:transactions-view');

        Route::put('/', [TransactionController::class, 'update'])
            ->middleware('can:transactions-edit');

        Route::delete('/', [TransactionController::class, 'destroy'])
            ->middleware('can:transactions-delete');

        Route::post('/verify-payment', [TempTransactionController::class, 'completePayment'])
            ->middleware('can:transactions-execute');
    });
});
