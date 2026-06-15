<?php

namespace Tests\Concerns;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;

trait CreatesAdminWithOrganizationPermissions
{
    use GrantsAdminPermissions;

    protected AdminUser $adminUser;

    protected string $adminToken;

    protected function setUpAdminWithOrganizationPermissions(): void
    {
        $role = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->grantMerchantPartnerAdminPermissions($role);
        $this->grantCommissionsHistoryAdminPermissions($role);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';
    }

    /**
     * @return array<int, array{name: string, value: bool, provider: string}>
     */
    protected function paymentMethodsPayloadWithPaypalEnabled(): array
    {
        return [
            ['name' => 'qrph', 'value' => true, 'provider' => 'paymongo'],
            ['name' => 'card', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'gcash', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'grab_pay', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'shopee_pay', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'billease', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'paymaya', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'dob', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'dob_fixed_minimum', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'brankas', 'value' => false, 'provider' => 'paymongo'],
            ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
        ];
    }
}
