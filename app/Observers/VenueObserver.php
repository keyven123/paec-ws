<?php

namespace App\Observers;

use App\Models\Venue;
use Illuminate\Support\Str;

class VenueObserver
{
    public function creating(Venue $venue): void
    {
        if (empty($venue->uuid)) {
            $venue->uuid = (string) Str::uuid();
        }
        $venue->created_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
        $venue->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }

    public function updating(Venue $venue): void
    {
        $venue->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }
}
