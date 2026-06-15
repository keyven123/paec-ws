<?php

namespace App\Observers;

use App\Models\Event;
use App\Services\ActivityComplianceService;
use Illuminate\Support\Str;

class EventObserver
{
    public function creating(Event $event): void
    {
        if (empty($event->uuid)) {
            $event->uuid = (string) Str::uuid();
        }
        $event->created_by = auth('admin')->user()->uuid ?? null;
        $event->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function created(Event $event): void
    {
        ActivityComplianceService::provisionDefaultsForEvent($event);
    }

    public function updating(Event $event): void
    {
        $event->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
