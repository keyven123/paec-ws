<?php

namespace App\Services;

use App\Models\Organization;
use Database\Seeders\PaecOrganizationSeeder;

class PaecOrganizationService
{
    public static function defaultOrganizationUuid(): ?string
    {
        return once(fn () => Organization::query()
            ->where(function ($query) {
                $query->where('email', 'inquire@paec.com')
                    ->orWhere('name', PaecOrganizationSeeder::PAEC_ORG_NAME);
            })
            ->value('uuid'));
    }
}
