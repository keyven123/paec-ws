<?php

namespace App\Services;

use App\Models\Organization;
use Database\Seeders\PaecOrganizationSeeder;

class PaecOrganizationService
{
    public static function defaultOrganizationUuid(): ?string
    {
        static $uuid = null;

        if ($uuid !== null) {
            return $uuid ?: null;
        }

        $organization = Organization::query()
            ->where(function ($query) {
                $query->where('email', 'inquire@paec.com')
                    ->orWhere('name', PaecOrganizationSeeder::PAEC_ORG_NAME);
            })
            ->first();

        $uuid = $organization?->uuid ?? '';

        return $uuid !== '' ? $uuid : null;
    }
}
