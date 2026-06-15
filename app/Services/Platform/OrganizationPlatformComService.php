<?php

namespace App\Services\Platform;

use App\Http\Repositories\OrganizationPlatformComRepository;
use App\Models\OrganizationPlatformCom;

class OrganizationPlatformComService
{
    public function __construct(
        protected OrganizationPlatformComRepository $organizationPlatformComRepository
    ) {
    }

    /**
     * Record a platform commission change (platform default or per-organization).
     */
    public function logCommissionChange(
        ?float $previousComs,
        float $currentComs,
        ?string $organizationUuid = null,
        ?string $createdBy = null,
    ): ?OrganizationPlatformCom {
        if (! $this->commissionValuesDiffer($previousComs, $currentComs)) {
            return null;
        }

        return $this->organizationPlatformComRepository->create([
            'organization_uuid' => $organizationUuid,
            'previous_coms' => $previousComs,
            'current_coms' => round($currentComs, 2),
            'created_by' => $createdBy ?? auth('admin')->user()?->uuid,
        ]);
    }

    public function commissionValuesDiffer(?float $previous, ?float $current): bool
    {
        if ($previous === null && $current === null) {
            return false;
        }

        if ($previous === null || $current === null) {
            return true;
        }

        return round($previous, 2) !== round($current, 2);
    }
}
