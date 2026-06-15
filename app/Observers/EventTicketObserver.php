<?php

namespace App\Observers;

use App\Models\EventTicket;
use Illuminate\Support\Str;

class EventTicketObserver
{
    public function creating(EventTicket $eventTicket): void
    {
        if (empty($eventTicket->uuid)) {
            $eventTicket->uuid = (string) Str::uuid();
        }
        $eventTicket->created_by = auth('admin')->user()->uuid ?? null;
        $eventTicket->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(EventTicket $eventTicket): void
    {
        $eventTicket->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
