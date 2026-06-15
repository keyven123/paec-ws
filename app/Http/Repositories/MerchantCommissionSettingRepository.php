<?php

namespace App\Http\Repositories;

use App\Models\Dataset;
use App\Services\Platform\OrganizationPlatformComService;

class MerchantCommissionSettingRepository
{
    public function __construct(
        protected OrganizationPlatformComService $organizationPlatformComService
    ) {
    }
    public function getSingleton(): Dataset
    {
        return Dataset::firstOrCreate(
            ['name' => 'merchant_commission_percentage'],
            [
                'description' => 'The default merchant commission percentage',
                'value' => '0',
            ]
        );
    }

    public function updateSingleton(float $defaultCommissionPercentage, ?string $adminUuid): Dataset
    {
        $row = $this->getSingleton();
        $previousValue = (float) $row->value;
        $newValue = round($defaultCommissionPercentage, 2);

        $this->organizationPlatformComService->logCommissionChange(
            $previousValue,
            $newValue,
            null,
            $adminUuid,
        );

        $row->value = (string) $newValue;
        $row->save();

        return $row;
    }
}
