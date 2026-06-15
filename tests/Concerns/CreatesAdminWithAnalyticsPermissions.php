<?php

namespace Tests\Concerns;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;

trait CreatesAdminWithAnalyticsPermissions
{
    protected AdminUser $analyticsAdmin;

    protected string $analyticsAdminToken;

    protected function setUpAnalyticsAdmin(): void
    {
        $role = Role::create([
            'name' => 'Analytics Admin',
            'code' => 'analytics-admin-test',
            'is_admin' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Analytics',
            'code' => 'analytics',
            'available_access' => ['view', 'export'],
        ]);

        RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'analytics-view',
        ]);

        $this->analyticsAdmin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'analytics-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Analytics',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->analyticsAdminToken = auth('admin')->login($this->analyticsAdmin) ?? '';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function analyticsGet(string $path, array $query = []): \Illuminate\Testing\TestResponse
    {
        $url = $query === [] ? $path : $path . '?' . http_build_query($query);

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->analyticsAdminToken,
        ])->getJson($url);
    }
}
