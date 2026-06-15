<?php

namespace App\Events\Abstracts;

use Illuminate\Support\Facades\Event;
use App\Models\PasswordSetup as ModelsPasswordSetup;

abstract class PasswordSetup extends Event
{
    public $passwordSetup;

    /**
     * Create a new event instance.
     * @param ModelsPasswordSetup $passwordSetup
     */
    public function __construct(ModelsPasswordSetup $passwordSetup)
    {
        $this->passwordSetup = $passwordSetup;
    }
}
