<?php

use App\Http\Controllers\BlockedDateController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\VenueListingController;
use Illuminate\Support\Facades\Route;

Route::prefix('venue-listings')->group(function () {
    Route::middleware(['can:venue-listings-view'])->group(function () {
        Route::get('/', [VenueListingController::class, 'index']);
        Route::get('/stats', [VenueListingController::class, 'stats']);
        Route::get('/{uuid}', [VenueListingController::class, 'show']);
        Route::get('/{uuid}/inquiries', [VenueListingController::class, 'listInquiries']);
        Route::get('/{uuid}/blocked-dates', [BlockedDateController::class, 'index'])
            ->defaults('blockableType', 'venue_listing');

        // Realtime chat thread for a given inquiry (merchant side).
        Route::get('/chat/unread-summary', [ChatController::class, 'unreadSummary']);
        Route::get('/inquiries/{inquiryUuid}', [VenueListingController::class, 'showInquiry']);
        Route::get('/inquiries/{inquiryUuid}/chat', [ChatController::class, 'show']);
    });

    Route::middleware(['can:venue-listings-create'])->group(function () {
        Route::post('/', [VenueListingController::class, 'store']);
    });

    Route::middleware(['can:venue-listings-update'])->group(function () {
        Route::patch('/inquiries/{inquiryUuid}', [VenueListingController::class, 'updateInquiry']);
        Route::post('/inquiries/{inquiryUuid}/proposal', [VenueListingController::class, 'sendProposal']);
        Route::post('/inquiries/{inquiryUuid}/deposit-request', [VenueListingController::class, 'requestDeposit']);
        Route::post('/inquiries/{inquiryUuid}/final-billing', [VenueListingController::class, 'sendFinalBilling']);
        Route::post('/inquiries/{inquiryUuid}/complete', [VenueListingController::class, 'completeInquiry']);
        Route::post('/inquiries/{inquiryUuid}/chat/messages', [ChatController::class, 'sendMessage']);
        Route::post('/chat/threads/{threadUuid}/read', [ChatController::class, 'markRead']);
        Route::put('/{uuid}', [VenueListingController::class, 'update']);
        Route::post('/{uuid}/blocked-dates', [BlockedDateController::class, 'store'])
            ->defaults('blockableType', 'venue_listing');
        Route::delete('/{uuid}/blocked-dates/{blocked_date_uuid}', [BlockedDateController::class, 'destroy'])
            ->defaults('blockableType', 'venue_listing');
    });

    Route::middleware(['can:venue-listings-delete'])->group(function () {
        Route::delete('/{uuid}', [VenueListingController::class, 'destroy']);
    });
});
