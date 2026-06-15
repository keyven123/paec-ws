<?php

namespace App\Observers;

use App\Models\PromoCode;
use Illuminate\Support\Str;

class PromoCodeObserver
{
    public function creating(PromoCode $promoCode): void
    {
        if (empty($promoCode->uuid)) {
            $promoCode->uuid = (string) Str::uuid();
        }
        $promoCode->created_by = auth('admin')->user()->uuid ?? null;
        $promoCode->updated_by = auth('admin')->user()->uuid ?? null;
    }

    public function updating(PromoCode $promoCode): void
    {
        $promoCode->updated_by = auth('admin')->user()->uuid ?? null;
    }
}

