<?php

namespace App\Observers;

use App\Models\Permission;
use Illuminate\Support\Str;

class PermissionObserver
{
    public function creating(Permission $permission): void
    {
        if (empty($permission->uuid)) {
            $permission->uuid = (string) Str::uuid();
        }
    }
}
