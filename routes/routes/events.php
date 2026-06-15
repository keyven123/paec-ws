<?php

use App\Http\Controllers\EventController;
use App\Http\Controllers\BlockedDateController;
use App\Http\Controllers\EventLocationController;
use Illuminate\Support\Facades\Route;

Route::prefix('events')->group(function () {
    Route::middleware(['can:events-view'])->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::get('/stats', [EventController::class, 'getEventStats']);
        Route::get('/fun-stats', [EventController::class, 'getFunStats']);
        Route::get('/{uuid}', [EventController::class, 'show']);
        Route::get('/{uuid}/scanned-attendees', [EventController::class, 'getScannedAttendees']);
        Route::get('/{uuid}/recent-purchased-tickets', [EventController::class, 'getRecentPurchasedTickets']);
        Route::get('/{uuid}/event-tickets-sold', [EventController::class, 'getEventTicketsSold']);
        Route::get('/{uuid}/locations', [EventLocationController::class, 'index']);
        Route::get('/{uuid}/ticket-calendar', [EventController::class, 'getEventTicketCalendar']);
        Route::get('/{uuid}/blocked-dates', [BlockedDateController::class, 'index'])
            ->defaults('blockableType', 'event');
    });

    Route::middleware(['can:events-create,fun-create'])->group(function () {
        Route::post('/', [EventController::class, 'store']);
    });

    Route::middleware(['can:events-update,fun-update'])->group(function () {
        Route::put('/{uuid}', [EventController::class, 'update']);
        Route::post('/{uuid}', [EventController::class, 'update']);
        Route::patch('/{uuid}/approve', [EventController::class, 'approve']);
        Route::patch('/{uuid}/publish', [EventController::class, 'publish']);
        Route::patch('/{uuid}/unpublish', [EventController::class, 'unpublish']);
        Route::patch('/{uuid}/cancel', [EventController::class, 'cancel']);
        Route::patch('/{uuid}/complete', [EventController::class, 'complete']);
        Route::patch('/{uuid}/feature', [EventController::class, 'feature']);
        Route::patch('/{uuid}/unfeature', [EventController::class, 'unfeature']);
        Route::patch('/{uuid}/submit-for-approval', [EventController::class, 'submitForApproval']);
        Route::patch('/{uuid}/cancel-for-approval', [EventController::class, 'cancelForApproval']);
        Route::patch('/{uuid}/request-for-featured', [EventController::class, 'requestForFeatured']);
        Route::patch('/{uuid}/cancel-for-featured', [EventController::class, 'cancelForFeatured']);
        Route::patch('/arrange-featured-events', [EventController::class, 'arrangeFeaturedEvents']);
        Route::patch('/{uuid}/today-cutoff', [EventController::class, 'updateTodayCutoff']);
        Route::post('/{uuid}/blocked-dates', [BlockedDateController::class, 'store'])
            ->defaults('blockableType', 'event');
        Route::delete('/{uuid}/blocked-dates/{blocked_date_uuid}', [BlockedDateController::class, 'destroy'])
            ->defaults('blockableType', 'event');
        Route::post('/{uuid}/locations', [EventLocationController::class, 'store']);
        Route::put('/{uuid}/locations/{location_uuid}', [EventLocationController::class, 'update']);
        Route::delete('/{uuid}/locations/{location_uuid}', [EventLocationController::class, 'destroy']);
    });

    Route::patch('/{uuid}/affiliate-settings', [EventController::class, 'updateAffiliateSettings']);

    Route::middleware(['can:events-delete,fun-delete'])->group(function () {
        Route::delete('/{uuid}', [EventController::class, 'destroy']);
    });

    Route::middleware(['can:events-export'])->group(function () {
        Route::get('/{uuid}/export', [EventController::class, 'export']);
        Route::get('/{uuid}/export-attendee-report', [EventController::class, 'exportAttendeeRegistrationReport']);
        Route::get('/{uuid}/export-used-tickets', [EventController::class, 'exportUsedTickets']);
        Route::get('/{uuid}/export-occupied-seats', [EventController::class, 'exportOccupiedSeats']);
        Route::get('/{uuid}/export-tickets', [EventController::class, 'exportTickets']);
    });
});
