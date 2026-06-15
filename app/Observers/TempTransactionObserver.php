<?php

namespace App\Observers;

use App\Models\TempTransaction;
use Illuminate\Support\Str;

class TempTransactionObserver
{
    public function creating(TempTransaction $tempTransaction): void
    {
        if (empty($tempTransaction->uuid)) {
            $tempTransaction->uuid = (string) Str::uuid();
        }
    }
}
