<?php

namespace App\Listeners;

use App\Events\Abstracts\PasswordReset;
use Illuminate\Support\Facades\Log;

class PasswordResetOtp
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Handle the event.
     *
     * @param PasswordReset $event
     *
     * @return void
     */
    public function handle(PasswordReset $event)
    {
        $event->passwordReset->sendOtp();
    }
}
