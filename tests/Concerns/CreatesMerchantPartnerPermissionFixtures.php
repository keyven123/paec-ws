<?php

namespace Tests\Concerns;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Organizer\OrganizerPermissionCatalogService;

trait CreatesMerchantPartnerPermissionFixtures
{
    use GrantsAdminPermissions;

    protected Organization $testOrganization;

    /**
     * @var array<string, Permission>
     */
    protected array $catalogPermissions = [];

    protected function createTestOrganization(): Organization
    {
        $this->testOrganization = Organization::create([
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'name' => 'Test Merchant',
            'representative_first_name' => 'Test',
            'representative_last_name' => 'Merchant',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant-permissions@test.com',
            'description' => 'Permission test organization',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        return $this->testOrganization;
    }

    protected function createPlatformAdminRole(array $overrides = []): Role
    {
        if (!isset($this->testOrganization)) {
            $this->createTestOrganization();
        }

        return Role::create(array_merge([
            'organization_uuid' => $this->testOrganization->uuid,
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ], $overrides));
    }

    protected function createOrganizerRole(array $overrides = []): Role
    {
        if (!isset($this->testOrganization)) {
            $this->createTestOrganization();
        }

        return Role::create(array_merge([
            'organization_uuid' => $this->testOrganization->uuid,
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ], $overrides));
    }

    /**
     * Seed Permission rows for every code in organizer_permissions.csv plus one admin-only module.
     *
     * @return array<string, Permission>
     */
    protected function seedOrganizerPermissionCatalog(): array
    {
        /** @var OrganizerPermissionCatalogService $catalogService */
        $catalogService = app(OrganizerPermissionCatalogService::class);
        $catalogByCode = $catalogService->getCatalogByCode();

        foreach ($catalogByCode as $code => $access) {
            $this->catalogPermissions[$code] = Permission::create([
                'name' => ucwords(str_replace('-', ' ', $code)),
                'code' => $code,
                'module' => 'Test Module',
                'available_access' => str_split('rwudxeia'),
                'role_scope' => 'shared',
            ]);
        }

        $this->catalogPermissions['dashboard'] = Permission::create([
            'name' => 'Dashboard',
            'code' => 'dashboard',
            'module' => 'Dashboard Module',
            'available_access' => ['r', 'x'],
            'role_scope' => 'admin',
        ]);

        return $this->catalogPermissions;
    }

    protected function grantRoleManagementPermissions(Role $role): void
    {
        $rolesPermission = $this->catalogPermissions['roles'] ?? Permission::where('code', 'roles')->first();

        if (!$rolesPermission) {
            $rolesPermission = Permission::create([
                'name' => 'Roles',
                'code' => 'roles',
                'module' => 'User Management Module',
                'available_access' => ['r', 'w', 'u', 'd', 'x'],
                'role_scope' => 'shared',
            ]);
            $this->catalogPermissions['roles'] = $rolesPermission;
        }

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $rolesPermission->uuid,
                'access' => 'roles-' . $access,
            ]);
        }
    }

    protected function createOrganizerAdminWithRolePermissions(): AdminUser
    {
        $this->createTestOrganization();
        $this->seedOrganizerPermissionCatalog();

        $role = $this->createOrganizerRole();
        $this->grantRoleManagementPermissions($role);

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $this->testOrganization->uuid,
            'email' => 'organizer-permissions@test.com',
            'password' => 'password123',
            'first_name' => 'Organizer',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    protected function createPlatformAdminWithRolePermissions(): AdminUser
    {
        $this->createTestOrganization();
        $this->seedOrganizerPermissionCatalog();

        $role = $this->createPlatformAdminRole();
        $this->grantRoleManagementPermissions($role);

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'platform-permissions@test.com',
            'password' => 'password123',
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }
}
