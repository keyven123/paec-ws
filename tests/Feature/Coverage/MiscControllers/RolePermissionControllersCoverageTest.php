<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\PermissionRoleScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolePermissionControllersCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin-only' => PermissionRoleScope::ADMIN,
            'organizer-only' => PermissionRoleScope::ORGANIZER,
            'shared-module' => PermissionRoleScope::SHARED,
        ] as $code => $scope) {
            Permission::create([
                'name' => $code,
                'code' => $code,
                'available_access' => ['view', 'update'],
                'role_scope' => $scope,
            ]);
        }
    }

    private function codesFrom(array $rows): array
    {
        $codes = array_column($rows, 'code');
        sort($codes);

        return $codes;
    }

    #[Test]
    public function permissionIndexFiltersByAdminScope(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/permissions?role_scope=admin')
            ->assertOk();

        $this->assertSame(['admin-only', 'shared-module'], $this->codesFrom($response->json('data')));
    }

    #[Test]
    public function permissionIndexFiltersByOrganizerScope(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/permissions?role_scope=organizer')
            ->assertOk();

        $this->assertSame(['organizer-only', 'shared-module'], $this->codesFrom($response->json('data')));
    }

    #[Test]
    public function permissionIndexFiltersBySharedScope(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/permissions?role_scope=shared')
            ->assertOk();

        $this->assertSame(['shared-module'], $this->codesFrom($response->json('data')));
    }

    #[Test]
    public function permissionsByOrganizerRoleScopeExcludeAdminOnlyPermissions(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/permissions/role-scope/organizer')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(['organizer-only', 'shared-module'], $this->codesFrom($response->json('data')));
    }

    #[Test]
    public function permissionsByInvalidRoleScopeReturns422(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/permissions/role-scope/bogus')
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid role_scope. Use admin or organizer.',
            ]);
    }

    #[Test]
    public function roleUpdateAppliesIsAdminFlagAndPermissionGrants(): void
    {
        $role = Role::create(['name' => 'Ops', 'code' => 'ops', 'is_admin' => false]);

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/roles/{$role->uuid}", [
                'uuid' => $role->uuid,
                'name' => 'Ops Team',
                'code' => 'ops-team',
                'is_admin' => true,
                'permission_grants' => [
                    ['code' => 'admin-only', 'available_access' => 'ru'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.permission_grants.0.code', 'admin-only')
            ->assertJsonPath('data.permission_grants.0.available_access', 'ru');

        $role->refresh();
        $this->assertTrue((bool) $role->is_admin);
        $this->assertSame('ops-team', $role->code);
        $this->assertDatabaseHas('role_permissions', ['role_uuid' => $role->uuid, 'access' => 'admin-only-view']);
        $this->assertDatabaseHas('role_permissions', ['role_uuid' => $role->uuid, 'access' => 'admin-only-update']);
    }
}
