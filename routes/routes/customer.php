<?php

use App\Http\Controllers\Customer\AffiliatePartnerController;
use App\Http\Controllers\Customer\ChatController;
use App\Http\Controllers\Customer\CouponController;
use App\Http\Controllers\Customer\NotificationController;
use App\Http\Controllers\Customer\TempTransactionController;
use App\Http\Controllers\Customer\TicketController;
use App\Http\Controllers\Customer\VenueInquiryController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'customer'], function () {
    Route::get('/affiliate', [AffiliatePartnerController::class, 'show']);
    Route::post('/affiliate/apply', [AffiliatePartnerController::class, 'apply']);
    Route::get('/affiliate/available-events', [AffiliatePartnerController::class, 'availableEvents']);
    Route::get('/affiliate/available-fun', [AffiliatePartnerController::class, 'availableFun']);
    Route::get('/affiliate/payout-requests', [AffiliatePartnerController::class, 'payoutHistory']);
    Route::post('/affiliate/payout-requests', [AffiliatePartnerController::class, 'storePayoutRequest']);
    Route::get('/affiliate/conversions', [AffiliatePartnerController::class, 'conversionHistory']);
    Route::patch('/affiliate/bank-details', [AffiliatePartnerController::class, 'updateBankDetails']);

    Route::get('/my-tickets', [TicketController::class, 'getMyTickets']);
    Route::get('/my-coupons', [CouponController::class, 'getMyCoupons']);
    Route::get('/my-transactions', [TransactionController::class, 'getMyTransactions']);
    Route::get('/my-venue-inquiries', [VenueInquiryController::class, 'getMyInquiries']);
    Route::post('/venue-inquiries/{uuid}/pay', [VenueInquiryController::class, 'pay']);
    Route::post('/venue-inquiries/{uuid}/accept-proposal', [VenueInquiryController::class, 'acceptProposal']);
    Route::post('/venue-inquiries/{uuid}/decline-proposal', [VenueInquiryController::class, 'declineProposal']);
    Route::post('/venue-inquiries/{uuid}/accept-visit', [VenueInquiryController::class, 'acceptVisitSchedule']);
    Route::post('/venue-inquiries/{uuid}/decline-visit', [VenueInquiryController::class, 'declineVisitSchedule']);
    Route::post('/venue-inquiries/{uuid}/suggest-visit-date', [VenueInquiryController::class, 'suggestVisitDate']);

    // In-app notifications.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{uuid}/read', [NotificationController::class, 'markRead']);

    // Realtime chat between the customer and the venue merchant (per inquiry).
    Route::get('/chat/unread-summary', [ChatController::class, 'unreadSummary']);
    Route::get('/venue-inquiries/{uuid}/chat', [ChatController::class, 'show']);
    Route::post('/venue-inquiries/{uuid}/chat/messages', [ChatController::class, 'sendMessage']);
    Route::post('/chat/threads/{threadUuid}/read', [ChatController::class, 'markRead']);
    Route::post('/tickets/{uuid}/transfer-by-email', [TicketController::class, 'transferMyTicketByEmail']);
    Route::put('/tickets/{uuid}', [TicketController::class, 'updateMyTicket']);
    Route::patch('/tickets/{uuid}/download', [TicketController::class, 'downloadTicket']);
    Route::patch('/tickets/{uuid}/use', [TicketController::class, 'useTicket']);
    Route::post('upload', [UploadController::class, 'store']);
    Route::get('/promo-codes/{uuid}', [PromoCodeController::class, 'showForCustomer'])
        ->whereUuid('uuid');
    Route::get('/promo-codes/{code}', [PromoCodeController::class, 'publicCode']);

    Route::group(['prefix' => 'temp-transactions'], function () {
        Route::get('/', [TempTransactionController::class, 'getTempTransaction']);
        Route::get('/{uuid}', [TempTransactionController::class, 'showTempTransactionByUuid']);
        Route::post('/', [TempTransactionController::class, 'tempTransaction']);
        Route::post('checkout', [TempTransactionController::class, 'checkout']);
        Route::post('checkout-free', [TempTransactionController::class, 'checkoutFree']);
        Route::post('checkout-bypass', [TempTransactionController::class, 'checkoutBypass']);
        Route::post('checkout-paypal-card', [TempTransactionController::class, 'checkoutPaypalCard']);
        Route::put('/{uuid}', [TempTransactionController::class, 'updateTempTransaction']);
        Route::delete('/{uuid}', [TempTransactionController::class, 'destroyTempTransaction']);
    });

    Route::post('transactions/{transactionUuid}/complete', [TempTransactionController::class, 'completePayment']);
    Route::post('transactions/{transactionUuid}/cancel', [TempTransactionController::class, 'cancelPayment']);
});
