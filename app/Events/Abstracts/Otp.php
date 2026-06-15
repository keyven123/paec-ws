<?php

namespace App\Events\Abstracts;

use App\Models\Otp as ModelOtp;
use Illuminate\Support\Facades\Event;

abstract class Otp extends Event
{
    public $otp;
    /**
     * Create a new event instance.
     * @param ModelOtp $otp
     */
    public function __construct(ModelOtp $otp)
    {
        $this->otp = $otp;
    }
}
