<?php

namespace App\Observers;

use App\Models\ScheduleTime;
use Illuminate\Support\Str;

class ScheduleTimeObserver
{
    public function creating(ScheduleTime $scheduleTime): void
    {
        if (empty($scheduleTime->uuid)) {
            $scheduleTime->uuid = (string) Str::uuid();
        }
        $scheduleTime->created_by = auth('admin')->user()->uuid ?? null;
        $scheduleTime->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(ScheduleTime $scheduleTime): void
    {
        $scheduleTime->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
