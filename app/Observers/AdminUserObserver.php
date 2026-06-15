<?php

namespace App\Observers;

use App\Models\AdminUser;
use Illuminate\Support\Str;

class AdminUserObserver
{
    public function creating(AdminUser $adminUser): void
    {
        if (empty($adminUser->uuid)) {
            $adminUser->uuid = (string) Str::uuid();
        }

        // Set default status if not provided
        if (empty($adminUser->status)) {
            $adminUser->status = \App\Constants\GeneralConstants::GENERAL_STATUSES['ACTIVE'];
        }

        // Set first time login flag
        if (is_null($adminUser->is_first_time_login)) {
            $adminUser->is_first_time_login = true;
        }

        // Set created_by and updated_by from authenticated admin user
        $currentUser = auth('admin')->user();
        if ($currentUser instanceof AdminUser) {
            $adminUser->created_by = $currentUser->uuid;
            $adminUser->updated_by = $currentUser->uuid;
        }
    }

    public function updating(AdminUser $adminUser): void
    {
        // Set updated_by from authenticated admin user
        $currentUser = auth('admin')->user();
        if ($currentUser instanceof AdminUser) {
            $adminUser->updated_by = $currentUser->uuid;
        }
    }

    public function created(AdminUser $adminUser): void
    {
      
    }
}
