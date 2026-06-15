<?php

namespace App\Models\Traits;

use App\Models\Otp;
use App\Http\Repositories\OtpRepository;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

trait Otpable
{
    /**
     * @return MorphOne
     */
    public function otp(): MorphOne
    {
        return $this->morphOne(Otp::class, 'otpable');
    }

    /**
     * @return MorphMany
     */
    public function otps(): MorphMany
    {
        return $this->morphMany(Otp::class, 'otpable');
    }

    /**
     * @return void
     */
    public function sendOtp(): void
    {
        $repository = app(OtpRepository::class);
        $repository->createAndSend($this);
    }
}
