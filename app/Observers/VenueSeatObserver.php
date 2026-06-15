<?php

namespace App\Observers;

use App\Models\VenueSeat;
use Illuminate\Support\Str;

class VenueSeatObserver
{
    public function creating(VenueSeat $venueSeat): void
    {
        if (empty($venueSeat->uuid)) {
            $venueSeat->uuid = (string) Str::uuid();
        }
        $venueSeat->created_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
        $venueSeat->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }

    public function updating(VenueSeat $venueSeat): void
    {
        $venueSeat->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }
}
