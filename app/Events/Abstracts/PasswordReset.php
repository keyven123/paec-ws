<?php

namespace App\Events\Abstracts;

use App\Models\PasswordReset as ModelPasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

abstract class PasswordReset extends Event
{
    public $passwordReset;

    /**
     * Create a new event instance.
     * @param ModelPasswordReset $passwordReset
     */
    public function __construct(ModelPasswordReset $passwordReset)
    {
        $this->passwordReset = $passwordReset;
    }
}
