<?php

namespace App\Observers;

use App\Helpers\GeneralHelper;
use App\Models\User;
use Illuminate\Support\Str;

class UserObserver
{
    public function creating(User $user): void
    {
        if (empty($user->uuid)) {
            $user->uuid = (string) Str::uuid();
        }
        $user->created_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
        $user->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
        $user->qr_code = GeneralHelper::generateQrCode($user, 'USER_');
    }

    public function updating(User $user): void
    {
        $user->updated_by = auth('api')->user()->uuid ?? auth('admin')->user()->uuid ?? null;
    }
}
