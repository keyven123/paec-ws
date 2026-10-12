<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Constants\PermissionRoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use App\Services\RolePermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolePermissionSyncServiceCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRole(bool $isAdmin): Role
    {
        return Role::create([
            'name' => 'Rsj Role '.Str::random(4),
            'code' => 'rsj_role_'.Str::lower(Str::random(6)),
            'is_admin' => $isAdmin,
        ]);
    }

    private function makePermission(string $code, string $scope): Permission
    {
        return Permission::create([
            'name' => Str::headline($code),
            'code' => $code,
            'available_access' => ['r', 'w', 'u', 'd'],
            'role_scope' => $scope,
        ]);
    }

    #[Test]
    public function syncGrantsForAdminRoleSkipsGrantsWithoutCodeOrAccess(): void
    {
        $role = $this->makeRole(true);
        $permission = $this->makePermission('finance', PermissionRoleScope::ADMIN);
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $permission->uuid, 'access' => 'finance-view']);

        app(RolePermissionSyncService::class)->syncGrants($role, [
            ['code' => '', 'available_access' => 'r'],
            ['available_access' => 'r'],
        ]);

        $this->assertSame(0, RolePermission::where('role_uuid', $role->uuid)->count());
    }

    #[Test]
    public function syncGrantsSkipsCodesWithoutMatchingPermission(): void
    {
        $catalog = $this->mock(OrganizerPermissionCatalogService::class);
        $catalog->shouldReceive('isCodeAllowed')->andReturnTrue();
        $catalog->shouldReceive('isAccessAllowed')->andReturnTrue();

        $role = $this->makeRole(false);
        $this->makePermission('events', PermissionRoleScope::SHARED);

        app(RolePermissionSyncService::class)->syncGrants($role, [
            ['code' => 'ghost', 'available_access' => 'r'],
            ['code' => 'events', 'available_access' => 'r'],
        ]);

        $this->assertSame(['events-view'], RolePermission::where('role_uuid', $role->uuid)->pluck('access')->all());
    }

    #[Test]
    public function validateGrantsForAdminRoleRejectsOrganizerOnlyPermissions(): void
    {
        $role = $this->makeRole(true);
        $this->makePermission('finance', PermissionRoleScope::ADMIN);
        $this->makePermission('merchant-only', PermissionRoleScope::ORGANIZER);

        try {
            app(RolePermissionSyncService::class)->syncGrants($role, [
                ['code' => 'finance', 'available_access' => 'r'],
                ['code' => 'merchant-only', 'available_access' => 'r'],
            ]);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['These permissions are not allowed for Admin roles: merchant-only'],
                $e->errors()['permission_grants'],
            );
        }
    }

    #[Test]
    public function validateGrantsForMerchantRoleIgnoresIncompleteGrants(): void
    {
        $role = $this->makeRole(false);

        app(RolePermissionSyncService::class)->validateGrantsForRole($role, [
            ['code' => '', 'available_access' => 'r'],
            ['code' => 'events'],
        ]);

        $this->assertSame(0, RolePermission::count());
    }

    #[Test]
    public function grantsFromRoleIgnoresOrphanedMismatchedAndUnknownActionRows(): void
    {
        $role = $this->makeRole(true);
        $events = $this->makePermission('events', PermissionRoleScope::SHARED);
        $orphan = $this->makePermission('orphan', PermissionRoleScope::SHARED);

        DB::statement('PRAGMA foreign_keys = OFF');
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $events->uuid, 'access' => 'events-view']);
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $events->uuid, 'access' => 'events-update']);
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $events->uuid, 'access' => 'tickets-view']);
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $events->uuid, 'access' => 'events-frobnicate']);
        RolePermission::create(['role_uuid' => $role->uuid, 'permission_uuid' => $orphan->uuid, 'access' => 'orphan-view']);
        DB::table('permissions')->where('uuid', $orphan->uuid)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $grants = app(RolePermissionSyncService::class)->grantsFromRole($role);

        $this->assertSame([['code' => 'events', 'available_access' => 'ru']], $grants);
    }
}
