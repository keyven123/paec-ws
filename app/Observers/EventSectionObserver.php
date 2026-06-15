<?php

namespace App\Observers;

use App\Models\EventSection;
use Illuminate\Support\Str;

class EventSectionObserver
{
    public function creating(EventSection $eventSection): void
    {
        if (empty($eventSection->uuid)) {
            $eventSection->uuid = (string) Str::uuid();
        }
        $eventSection->created_by = auth('admin')->user()->uuid ?? null;
        $eventSection->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(EventSection $eventSection): void
    {
        $eventSection->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
