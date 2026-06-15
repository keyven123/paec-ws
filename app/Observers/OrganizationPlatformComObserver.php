<?php

namespace App\Observers;

use App\Models\OrganizationPlatformCom;
use Illuminate\Support\Str;

class OrganizationPlatformComObserver
{
    public function creating(OrganizationPlatformCom $organizationPlatformCom): void
    {
        if (empty($organizationPlatformCom->uuid)) {
            $organizationPlatformCom->uuid = (string) Str::uuid();
        }
    }
}
