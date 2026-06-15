<?php

namespace App\Observers;

use App\Helpers\GeneralHelper;
use App\Models\Ticket;
use Illuminate\Support\Str;

class TicketObserver
{
    public function creating(Ticket $ticket): void
    {
        if (empty($ticket->uuid)) {
            $ticket->uuid = (string) Str::uuid();
        }

        $ticket->created_by = auth('admin')->user()->uuid ?? null;
        $ticket->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(Ticket $ticket): void
    {
        $ticket->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
