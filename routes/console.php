<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:expire-tickets')->dailyAt('00:10');
Schedule::command('app:auto-verify-pending-transaction')->everyFiveMinutes()->withoutOverlapping();
// End-of-day sweep: re-check today's pending + cancelled orders in case payment pushed through.
Schedule::command('app:auto-verify-pending-transaction --include-cancelled --today')
    ->dailyAt('23:59')
    ->timezone(config('app.timezone', 'Asia/Manila'))
    ->withoutOverlapping();
Schedule::command('app:send-cart-reminders')->everyFiveMinutes()->withoutOverlapping();
