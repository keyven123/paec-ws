<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use App\Services\RolePermissionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesMerchantPartnerPermissionFixtures;
use Tests\TestCase;

class RolePermissionSyncServiceTest extends TestCase
{
    use CreatesMerchantPartnerPermissionFixtures;
    use RefreshDatabase;

    private RolePermissionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RolePermissionSyncService::class);
        $this->createTestOrganization();
        $this->seedOrganizerPermissionCatalog();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSyncsValidMerchantPartnerPermissionGrants(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'custom-merchant-role',
            'name' => 'Custom Merchant Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $this->service->syncGrants($role, [
            ['code' => 'events', 'available_access' => 'rw'],
            ['code' => 'categories', 'available_access' => 'r'],
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'events-view',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'events-create',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'categories-view',
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'categories-create',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsMerchantPartnerGrantsOutsideCatalogCodes(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'invalid-grant-role',
            'name' => 'Invalid Grant Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->syncGrants($role, [
            ['code' => 'dashboard', 'available_access' => 'r'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsMerchantPartnerGrantsWithDisallowedAccessLetters(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'invalid-access-role',
            'name' => 'Invalid Access Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->syncGrants($role, [
            ['code' => 'categories', 'available_access' => 'rw'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAllowsAdminRolesToUseAdminScopedPermissions(): void
    {
        Permission::create([
            'name' => 'Dashboard',
            'code' => 'dashboard',
            'module' => 'Dashboard Module',
            'available_access' => ['r', 'x'],
            'role_scope' => 'admin',
        ]);

        $role = $this->createPlatformAdminRole();

        $this->service->syncGrants($role, [
            ['code' => 'dashboard', 'available_access' => 'rx'],
        ]);

        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'dashboard-view',
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $role->uuid,
            'access' => 'dashboard-execute',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itBuildsGrantPayloadsFromStoredRolePermissions(): void
    {
        $role = $this->createOrganizerRole([
            'code' => 'grant-roundtrip-role',
            'name' => 'Grant Roundtrip Role',
            'organization_uuid' => $this->testOrganization->uuid,
        ]);

        $this->service->syncGrants($role, [
            ['code' => 'venues', 'available_access' => 'r'],
            ['code' => 'promo-codes', 'available_access' => 'rw'],
        ]);

        $grants = $this->service->grantsFromRole($role);

        $this->assertEquals([
            ['code' => 'promo-codes', 'available_access' => 'rw'],
            ['code' => 'venues', 'available_access' => 'r'],
        ], collect($grants)->sortBy('code')->values()->all());
    }
}
