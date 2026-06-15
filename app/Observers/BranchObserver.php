<?php

namespace App\Observers;

use App\Models\Branch;
use Illuminate\Support\Str;

class BranchObserver
{
    public function creating(Branch $branch): void
    {
        if (empty($branch->uuid)) {
            $branch->uuid = (string) Str::uuid();
        }
    }
}
