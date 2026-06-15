<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class QrCodeServiceProviderAlias extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->alias(QrCode::class, 'QrCode');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
