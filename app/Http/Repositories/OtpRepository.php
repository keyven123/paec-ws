<?php

namespace App\Http\Repositories;

use App\Models\Otp;
use App\Events\OtpWasSent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class OtpRepository
{
    private $otp;
    /**
     * @param Otp $otp
     */
    public function __construct(Otp $otp)
    {
        $this->otp = $otp;
    }

    /**
     * @param Model $model
     * @return Otp
     */
    public function createAndSend(Model $model): Otp
    {
        $secret = str_pad(rand(000000, 999999), 6, '0', STR_PAD_LEFT);
        $model->otps()->delete();

        $otp = $model->otp()->create([
            'secret' => $secret,
            'receiver' => $model->email,
        ]);

        event(new OtpWasSent($otp));
        return $otp;
    }

    /**
     * @param Otp $otp
     * @return Model
     */
    public function confirm(Otp $otp): Model
    {
        $otpable = $otp->otpable;
        $otpable->confirmOtp();
        $otp->delete();
        return $otpable->fresh();
    }
}
