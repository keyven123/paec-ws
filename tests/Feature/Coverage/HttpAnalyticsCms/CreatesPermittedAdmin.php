<?php

namespace Tests\Feature\Coverage\HttpAnalyticsCms;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use Tests\Concerns\GrantsAdminPermissions;

trait CreatesPermittedAdmin
{
    use GrantsAdminPermissions;

    protected AdminUser $admin;

    protected string $adminToken;

    /**
     * @param  array<string, list<string>>  $modules  e.g. ['cms' => ['view', 'update']]
     */
    protected function actingAsPermittedAdmin(array $modules): void
    {
        $role = Role::create([
            'name' => 'Coverage Admin',
            'code' => 'coverage-admin-' . uniqid(),
            'is_admin' => true,
        ]);

        $this->grantRolePermissions($role, $modules);

        $this->admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'coverage-admin-' . uniqid() . '@test.com',
            'password' => 'password123',
            'first_name' => 'Coverage',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->admin);
    }

    protected function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken];
    }
}
