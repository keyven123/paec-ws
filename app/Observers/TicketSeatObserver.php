<?php

namespace App\Observers;

use App\Models\TicketSeat;
use Illuminate\Support\Str;

class TicketSeatObserver
{
    public function creating(TicketSeat $ticketSeat): void
    {
        if (empty($ticketSeat->uuid)) {
            $ticketSeat->uuid = (string) Str::uuid();
        }
        $ticketSeat->created_by = auth('admin')->user()->uuid ?? null;
        $ticketSeat->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(TicketSeat $ticketSeat): void
    {
        $ticketSeat->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
