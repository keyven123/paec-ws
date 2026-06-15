<?php

namespace App\Observers;

use App\Models\Otp;
use Carbon\Carbon;

class OtpObserver
{
    /**
     * @param Otp $otp
     * @return void
     */
    public function creating(Otp $otp): void
    {
        $config = config('otp');
        $otp->expires_at = (new Carbon())->addMinutes(30);
        $otp->resendable_at = (new Carbon())->addSeconds($config['resend']);
    }
}
