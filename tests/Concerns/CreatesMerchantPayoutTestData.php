<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\OrganizationBank;

trait CreatesMerchantPayoutTestData
{
    protected function createOrganizationBank(Organization $organization, array $overrides = []): OrganizationBank
    {
        return OrganizationBank::query()->create(array_merge([
            'organization_uuid' => $organization->uuid,
            'account_type' => OrganizationBank::ACCOUNT_TYPE_SAVINGS,
            'bank_name' => 'Test Bank',
            'bank_branch' => 'Main Branch',
            'bank_address' => 'Bank Address',
            'bank_account_name' => 'Account Holder',
            'bank_account_number' => '1234567890',
            'is_default' => true,
            'status' => OrganizationBank::STATUS_ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function merchantPayoutAttributes(Organization $organization, array $overrides = []): array
    {
        if (! array_key_exists('organization_bank_uuid', $overrides)) {
            $bank = OrganizationBank::query()
                ->where('organization_uuid', $organization->uuid)
                ->orderByDesc('is_default')
                ->first();

            if ($bank === null) {
                $bank = $this->createOrganizationBank($organization);
            }

            $overrides['organization_bank_uuid'] = $bank->uuid;
        }

        return array_merge([
            'organization_uuid' => $organization->uuid,
            'amount_requested' => 100,
            'currency' => 'PHP',
        ], $overrides);
    }
}
