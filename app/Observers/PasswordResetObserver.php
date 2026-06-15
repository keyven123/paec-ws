<?php

namespace App\Observers;

use App\Models\PasswordReset;
use App\Events\PasswordResetWasCreated;

class PasswordResetObserver
{
    /**
     * Creating
     *
     * @param PasswordReset $passwordReset
     *
     * @return void
     */
    public function creating(PasswordReset $passwordReset): void
    {
        $passwordReset->setExpiration();
    }

    /**
     * Created
     *
     * @param PasswordReset $passwordReset
     *
     * @return void
     */
    public function created(PasswordReset $passwordReset): void
    {
        event(new PasswordResetWasCreated($passwordReset));
    }
}
