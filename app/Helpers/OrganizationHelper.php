<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

class OrganizationHelper
{
    /**
     * @param Builder $query
     * @return Builder
     */
    public static function tenantOrganization(Builder $query): Builder
    {
        $organizationUuid = auth('admin')->user()->organization_uuid;
        if ($organizationUuid) {
            $query = $query->where('organization_uuid', $organizationUuid);
        }
        return $query;
    }
}
