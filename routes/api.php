<?php

use App\Http\Controllers\AffiliatePublicController;
use App\Http\Controllers\AuthAdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BroadcastingAuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventTicketController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\VenueListingController;
use App\Http\Controllers\VenueSeatController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'type' => 'api',
        'name' => 'PAEC',
        'version' => '1.0.0',
        'description' => 'PAEC API',
    ]);
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $dbStatus = 'connected';
    } catch (\Throwable) {
        $dbStatus = 'disconnected';
    }

    return response()->json([
        'status' => $dbStatus === 'connected' ? 'ok' : 'degraded',
        'database' => $dbStatus,
        'timestamp' => now()->toIso8601String(),
    ], $dbStatus === 'connected' ? 200 : 503);
});

Route::prefix('v1')->group(function () {
    Route::get('/auth/google/redirect', [SocialAuthController::class, 'googleRedirect']);
    Route::get('/auth/google/callback', [SocialAuthController::class, 'googleCallback']);
    Route::get('/auth/facebook/redirect', [SocialAuthController::class, 'facebookRedirect']);
    Route::get('/auth/facebook/callback', [SocialAuthController::class, 'facebookCallback']);

    Route::middleware('throttle:50,1')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('register', [AuthController::class, 'register']);
        Route::post('verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('resend-verification', [AuthController::class, 'resendVerification']);
        Route::post('organizer/login', [OrganizationController::class, 'login']);
        Route::post('organizer/register', [OrganizationController::class, 'register']);
        Route::get('datasets/site-visit', [DatasetController::class, 'getSiteVisit']);
        Route::post('datasets/site-visit/increment', [DatasetController::class, 'incrementSiteVisit']);
        Route::post('organizations/register', [OrganizationController::class, 'register']);
        Route::get('organizations/onboarding', [OrganizationController::class, 'onboarding'])->name('organizer.onboarding');
        Route::post('admin/login', [AuthAdminController::class, 'login']);

        Route::group(['prefix' => 'public'], function () {
            Route::get('/categories', [CategoryController::class, 'publicCategories']);
            Route::get('/places', [VenueController::class, 'publicPlaces']);
            Route::get('/organizations', [OrganizationController::class, 'publicOrganizations']);
            Route::post('/affiliate/track-click', [AffiliatePublicController::class, 'trackClick']);

            Route::group(['prefix' => 'events'], function () {
                Route::get('/', [EventController::class, 'publicEvents']);
                Route::get('/browse-by-city', [EventController::class, 'publicBrowseByCity']);
                Route::get('/{uuid}', [EventController::class, 'showEventPublic']);
                Route::get('/{uuid}/seats', [VenueSeatController::class, 'getVenueSeatsPublic']);
                Route::get('/{uuid}/seats-v2', [VenueSeatController::class, 'getVenueSeatsPublicV2']);
                Route::get('/{uuid}/schedule', [ScheduleController::class, 'getEventSchedulePublic']);
                Route::get('/{uuid}/tickets', [EventTicketController::class, 'getEventTicketsPublic']);
                Route::get('/{uuid}/blocked-dates', [\App\Http\Controllers\BlockedDateController::class, 'index'])
                    ->defaults('blockableType', 'event');
                Route::get('/{uuid}/ticket-calendar', [EventController::class, 'getEventTicketCalendar']);
            });

            Route::get('/promo-codes/{code}', [PromoCodeController::class, 'validateForEvent']);

            Route::group(['prefix' => 'venue-listings'], function () {
                Route::get('/', [VenueListingController::class, 'publicListings']);
                Route::get('/{slug}', [VenueListingController::class, 'showPublic']);
                Route::post('/{slug}/inquiries', [VenueListingController::class, 'storeInquiry']);
                Route::get('/{slug}/blocked-dates', [\App\Http\Controllers\BlockedDateController::class, 'publicIndexBySlug']);
            });

            Route::get('/cms/footer', [\App\Http\Controllers\CmsController::class, 'publicFooter']);
            Route::get('/cms/pages', [\App\Http\Controllers\CmsController::class, 'publicPages']);
            Route::get('/cms/pages/{slug}', [\App\Http\Controllers\CmsController::class, 'publicShow']);
        });
    });

    Route::group(['middleware' => 'auth:api'], function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [ProfileController::class, 'updateProfile']);
        Route::delete('profile/delete', [ProfileController::class, 'deleteAccount']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('broadcasting/auth', [BroadcastingAuthController::class, 'customer']);
        include __DIR__.'/routes/customer.php';
    });

    include __DIR__.'/routes/password-reset.php';
    include __DIR__.'/routes/organizer.php';

    Route::post('uploads/proxy-image', [UploadController::class, 'proxyImage']);

    Route::group(['middleware' => 'auth:admin'], function () {
        Route::post('admin/broadcasting/auth', [BroadcastingAuthController::class, 'admin']);
        include __DIR__.'/routes/admin_auth.php';
        include __DIR__.'/routes/core.php';
        include __DIR__.'/routes/roles.php';
        include __DIR__.'/routes/users.php';
        include __DIR__.'/routes/admin_users.php';
        include __DIR__.'/routes/events.php';
        include __DIR__.'/routes/categories.php';
        include __DIR__.'/routes/event-sections.php';
        include __DIR__.'/routes/venues.php';
        include __DIR__.'/routes/venue_listings.php';
        include __DIR__.'/routes/schedules.php';
        include __DIR__.'/routes/schedule-times.php';
        include __DIR__.'/routes/event-tickets.php';
        include __DIR__.'/routes/venue_seats.php';
        include __DIR__.'/routes/transactions.php';
        include __DIR__.'/routes/tickets.php';
        include __DIR__.'/routes/ticket_seats.php';
        include __DIR__.'/routes/organizations.php';
        include __DIR__.'/routes/organization_platform_coms.php';
        include __DIR__.'/routes/merchant_commission_settings.php';
        include __DIR__.'/routes/analytics.php';
        include __DIR__.'/routes/admin_finance.php';
        include __DIR__.'/routes/dashboard.php';
        include __DIR__.'/routes/uploads.php';
        include __DIR__.'/routes/promo_codes.php';
        include __DIR__.'/routes/cms.php';
        include __DIR__.'/routes/ticket-coupons.php';
        include __DIR__.'/routes/payment_gateway_rate_settings.php';
        include __DIR__.'/routes/default_payment_methods_settings.php';
        include __DIR__.'/routes/affiliate_payout_requests.php';
        include __DIR__.'/routes/merchant_payout_requests.php';
        include __DIR__.'/routes/activity_compliances.php';
        include __DIR__.'/routes/markups.php';

        Route::get('admin/notifications', [NotificationController::class, 'index']);
        Route::get('admin/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('admin/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('admin/notifications/{uuid}/read', [NotificationController::class, 'markRead']);
    });
});
