<?php

namespace App\Observers;

use App\Models\Role;
use Illuminate\Support\Str;

class RoleObserver
{
    public function creating(Role $role): void
    {
        if (empty($role->uuid)) {
            $role->uuid = (string) Str::uuid();
        }
        $role->created_by = auth('admin')->user()->uuid ?? null;
        $role->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(Role $role): void
    {
        $role->updated_by = auth('admin')->user()->uuid ?? null;
    }
}
