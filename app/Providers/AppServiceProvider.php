<?php

namespace App\Providers;

use App\Models\AdminUser;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\BlockedDate;
use App\Models\Otp;
use App\Models\PasswordReset;
use App\Models\PasswordSetup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\TempTransaction;
use App\Models\Upload;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueInquiry;
use App\Models\VenueSeat;
use App\Models\OrganizationPlatformCom;
use App\Models\ActivityCompliance;
use App\Models\PromoCode;
use App\Observers\ActivityComplianceObserver;
use App\Observers\AdminUserObserver;
use App\Observers\BranchObserver;
use App\Observers\CategoryObserver;
use App\Observers\EventObserver;
use App\Observers\EventSectionObserver;
use App\Observers\EventTicketObserver;
use App\Observers\BlockedDateObserver;
use App\Observers\OtpObserver;
use App\Observers\PasswordResetObserver;
use App\Observers\PasswordSetupObserver;
use App\Observers\PermissionObserver;
use App\Observers\RoleObserver;
use App\Observers\ScheduleObserver;
use App\Observers\ScheduleTimeObserver;
use App\Observers\TicketObserver;
use App\Observers\TicketSeatObserver;
use App\Observers\TransactionObserver;
use App\Observers\TempTransactionObserver;
use App\Observers\UserObserver;
use App\Observers\VenueObserver;
use App\Observers\VenueSeatObserver;
use App\Observers\UploadObserver;
use App\Observers\OrganizationPlatformComObserver;
use App\Observers\PromoCodeObserver;
use App\Services\Checkout\CheckoutHandlerRegistry;
use App\Services\Checkout\EventCheckoutHandler;
use App\Services\Checkout\VenueCheckoutHandler;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CheckoutHandlerRegistry::class, function ($app) {
            $registry = new CheckoutHandlerRegistry();
            $registry->register($app->make(EventCheckoutHandler::class));
            $registry->register($app->make(VenueCheckoutHandler::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AdminUser::observe(AdminUserObserver::class);
        Branch::observe(BranchObserver::class);
        Category::observe(CategoryObserver::class);
        Event::observe(EventObserver::class);
        EventSection::observe(EventSectionObserver::class);
        EventTicket::observe(EventTicketObserver::class);
        BlockedDate::observe(BlockedDateObserver::class);
        Permission::observe(PermissionObserver::class);
        Role::observe(RoleObserver::class);
        Schedule::observe(ScheduleObserver::class);
        ScheduleTime::observe(ScheduleTimeObserver::class);
        Ticket::observe(TicketObserver::class);
        TicketSeat::observe(TicketSeatObserver::class);
        Transaction::observe(TransactionObserver::class);
        User::observe(UserObserver::class);
        Venue::observe(VenueObserver::class);
        VenueSeat::observe(VenueSeatObserver::class);
        TempTransaction::observe(TempTransactionObserver::class);
        Upload::observe(UploadObserver::class);
        PasswordReset::observe(PasswordResetObserver::class);
        Otp::observe(OtpObserver::class);
        PasswordSetup::observe(PasswordSetupObserver::class);
        OrganizationPlatformCom::observe(OrganizationPlatformComObserver::class);
        PromoCode::observe(PromoCodeObserver::class);
        ActivityCompliance::observe(ActivityComplianceObserver::class);

        Relation::morphMap([
            'password_reset' => PasswordReset::class,
            'password_setup' => PasswordSetup::class,
            'event' => Event::class,
            'venue_inquiry' => VenueInquiry::class,
        ]);

        // Register private broadcast channel authorization callbacks. The
        // broadcasting auth HTTP routes are defined manually in routes/api.php
        // so they can run through the JWT guards (api + admin) instead of the
        // default session-based broadcasting route.
        require base_path('routes/channels.php');
    }
}
