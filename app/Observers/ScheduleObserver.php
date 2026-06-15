<?php

namespace App\Observers;

use App\Models\Schedule;
use Illuminate\Support\Str;

class ScheduleObserver
{
    public function creating(Schedule $schedule): void
    {
        if (empty($schedule->uuid)) {
            $schedule->uuid = (string) Str::uuid();
        }
        $schedule->created_by = auth('admin')->user()->uuid ?? null;
        $schedule->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(Schedule $schedule): void
    {
        $schedule->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
