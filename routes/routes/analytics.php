<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Organizer\OrganizerAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('analytics')->group(function () {
    Route::get('/stats', [AnalyticsController::class, 'stats'])->middleware('can:analytics-view');
    Route::get('/sales', [AnalyticsController::class, 'sales'])->middleware('can:analytics-view');
    Route::get('/events', [AnalyticsController::class, 'events'])->middleware('can:analytics-view');
    Route::get('/sales-report', [AnalyticsController::class, 'salesReport'])->middleware('can:analytics-view');
    Route::get('/cancelled-checkouts', [AnalyticsController::class, 'cancelledCheckouts'])->middleware('can:analytics-view');
    Route::get('/transaction-revenue-series', [AnalyticsController::class, 'transactionRevenueSeries'])->middleware('can:analytics-view');
    Route::get('/transaction-revenue-series/export', [AnalyticsController::class, 'exportTransactionRevenueSeries'])->middleware('can:analytics-view');
    Route::get('/successful-failed-transaction-counts', [AnalyticsController::class, 'successfulFailedTransactionCountsSeries'])->middleware('can:analytics-view');
    Route::get('/user-signups-series', [AnalyticsController::class, 'userSignupsSeries'])->middleware('can:analytics-view');
    Route::get('/revenue-per-event-series', [AnalyticsController::class, 'revenuePerEventSeries'])->middleware('can:analytics-view');
    Route::get('/revenue-by-event-pie', [AnalyticsController::class, 'revenueByEventPie'])->middleware('can:analytics-view');
    Route::get('/customer-type-pie', [AnalyticsController::class, 'customerTypePie'])->middleware('can:analytics-view');
    Route::get('/successful-failed-transaction-pie', [AnalyticsController::class, 'successfulFailedTransactionPie'])->middleware('can:analytics-view');

    Route::prefix('organizer')->group(function () {
        Route::get('/stats', [OrganizerAnalyticsController::class, 'stats'])->middleware('can:organizer-analytics-view');
        Route::get('/sales', [OrganizerAnalyticsController::class, 'sales'])->middleware('can:organizer-analytics-view');
        Route::get('/events', [OrganizerAnalyticsController::class, 'events'])->middleware('can:organizer-analytics-view');
        Route::get('/transaction-revenue-series', [OrganizerAnalyticsController::class, 'transactionRevenueSeries'])->middleware('can:organizer-analytics-view');
        Route::get('/transaction-revenue-series/export', [OrganizerAnalyticsController::class, 'exportTransactionRevenueSeries'])->middleware('can:organizer-analytics-view');
        Route::get('/successful-failed-transaction-counts', [OrganizerAnalyticsController::class, 'successfulFailedTransactionCountsSeries'])->middleware('can:organizer-analytics-view');
        Route::get('/revenue-per-event-series', [OrganizerAnalyticsController::class, 'revenuePerEventSeries'])->middleware('can:organizer-analytics-view');
        Route::get('/customer-type-pie', [OrganizerAnalyticsController::class, 'customerTypePie'])->middleware('can:organizer-analytics-view');
        Route::get('/successful-failed-transaction-pie', [OrganizerAnalyticsController::class, 'successfulFailedTransactionPie'])->middleware('can:organizer-analytics-view');
    });
});
