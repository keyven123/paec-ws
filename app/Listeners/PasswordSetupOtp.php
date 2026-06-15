<?php

namespace App\Listeners;

use App\Events\Abstracts\PasswordSetup;

class PasswordSetupOtp
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
     * @param PasswordSetup $event
     *
     * @return void
     */
    public function handle(PasswordSetup $event)
    {
        $event->passwordSetup->sendOtp();
    }
}
