<?php

namespace App\Observers;

use App\Models\BlockedDate;
use Illuminate\Support\Str;

class BlockedDateObserver
{
    public function creating(BlockedDate $blockedDate): void
    {
        if (empty($blockedDate->uuid)) {
            $blockedDate->uuid = (string) Str::uuid();
        }
        $blockedDate->created_by = auth('api')->user()->uuid ?? null;
        $blockedDate->updated_by = auth('api')->user()->uuid ?? null;
    }

    public function updating(BlockedDate $blockedDate): void
    {
        $blockedDate->updated_by = auth('api')->user()->uuid ?? null;
    }
}
