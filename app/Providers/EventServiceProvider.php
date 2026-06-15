<?php

namespace App\Providers;

use App\Events\OtpWasSent;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Events\PasswordResetWasCreated;
use App\Events\ResetPasswordSetup;
use App\Listeners\PasswordResetOtp;
use App\Listeners\PasswordSetupOtp;
use App\Listeners\SendOtp;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        PasswordResetWasCreated::class => [
            PasswordResetOtp::class,
        ],
        PasswordResetExpirationWasRefreshed::class => [
            PasswordResetOtp::class,
        ],
        OtpWasSent::class => [
            SendOtp::class
        ],
        ResetPasswordSetup::class => [
            PasswordSetupOtp::class
        ],
    ];

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
