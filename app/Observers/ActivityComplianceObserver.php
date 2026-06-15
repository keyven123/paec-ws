<?php

namespace App\Observers;

use App\Models\ActivityCompliance;
use App\Models\ActivityComplianceHistory;
use Illuminate\Support\Str;

class ActivityComplianceObserver
{
    public function creating(ActivityCompliance $activityCompliance): void
    {
        if (empty($activityCompliance->uuid)) {
            $activityCompliance->uuid = (string) Str::uuid();
        }

        $userUuid = auth('admin')->user()->uuid ?? auth('api')->user()->uuid ?? null;
        if ($userUuid && empty($activityCompliance->updated_by_uuid)) {
            $activityCompliance->updated_by_uuid = $userUuid;
        }
    }

    public function updating(ActivityCompliance $activityCompliance): void
    {
        $userUuid = auth('admin')->user()->uuid ?? auth('api')->user()->uuid ?? null;
        if ($userUuid) {
            $activityCompliance->updated_by_uuid = $userUuid;
        }

        $changes = [];
        foreach (ActivityCompliance::AUDIT_ATTRIBUTES as $attribute) {
            if (! $activityCompliance->isDirty($attribute)) {
                continue;
            }

            $changes[$attribute] = [
                'from' => $activityCompliance->getOriginal($attribute),
                'to' => $activityCompliance->{$attribute},
            ];
        }

        if ($changes === []) {
            return;
        }

        $previous = [];
        $current = [];
        foreach ($changes as $attribute => $delta) {
            $previous[$attribute] = $delta['from'];
            $current[$attribute] = $delta['to'];
        }

        ActivityComplianceHistory::query()->create([
            'activity_compliance_uuid' => $activityCompliance->uuid,
            'previous_value' => $previous,
            'current_value' => $current,
            'created_by' => $userUuid,
            'created_at' => now(),
        ]);
    }
}
