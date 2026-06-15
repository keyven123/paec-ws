<?php

namespace App\Observers;

use Carbon\Carbon;
use App\Models\PasswordSetup;
use App\Events\ResetPasswordSetup;

class PasswordSetupObserver
{
    /**
     * Saving
     *
     * @param PasswordSetup $model
     *
     * @return void
     */
    public function saving(PasswordSetup $model): void
    {
        $model->expires_at = (new Carbon())->addHour();
    }

    /**
     * Saved
     *
     * @param PasswordSetup $model
     *
     * @return void
     */
    public function saved(PasswordSetup $model): void
    {
        event(new ResetPasswordSetup($model));
    }
}
