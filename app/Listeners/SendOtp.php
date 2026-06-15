<?php

namespace App\Listeners;

use App\Events\OtpWasSent;
use App\Notifications\SendPasswordResetEmailNotification;
use App\Notifications\SendPasswordSetupEmailNotification;
use Illuminate\Support\Facades\Log;

class SendOtp
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
     * @param OtpWasSent $event
     *
     * @return void
     */
    public function handle(OtpWasSent $event)
    {
        switch ($event->otp->otpable_type) {
            case 'password_reset':
                $event->otp->notify(new SendPasswordResetEmailNotification());
                break;
            case 'password_setup':
                $event->otp->notify(new SendPasswordSetupEmailNotification());
                break;
        }
    }
}
