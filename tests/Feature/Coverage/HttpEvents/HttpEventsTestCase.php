<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

abstract class HttpEventsTestCase extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    /**
     * Create an admin with the given module permissions and return [admin, bearer token].
     *
     * @param  array<string, list<string>>  $modules
     * @return array{0: AdminUser, 1: string}
     */
    protected function createAdmin(
        array $modules = [],
        string $roleCode = 'admin',
        bool $isAdmin = true,
        ?string $organizationUuid = null,
    ): array {
        $role = Role::create([
            'name' => ucfirst($roleCode) . ' ' . Str::random(4),
            'code' => $roleCode,
            'is_admin' => $isAdmin,
        ]);

        if ($modules !== []) {
            $this->grantRolePermissions($role, $modules);
        }

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organizationUuid,
            'email' => 'cov-' . Str::lower(Str::random(8)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Coverage',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $token = auth('admin')->login($admin);

        return [$admin, $token];
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Switch to a cache store without tag support so controllers hit their version-bump fallback.
     */
    protected function useCacheStoreWithoutTags(): void
    {
        config(['cache.default' => 'database']);
    }
}
