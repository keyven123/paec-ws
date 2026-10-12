<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Http\Controllers\PortalController;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The portal routes are not reachable over HTTP (see the `portal:` middleware alias and the
 * `api` guard used under the `auth:admin` group), so the controller is exercised directly.
 */
class PortalControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createCustomer();
        $permission = Permission::create([
            'name' => 'View Events',
            'code' => 'view_events',
            'available_access' => ['view'],
        ]);
        RolePermission::create([
            'role_uuid' => $this->customer->role_uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'view_events',
        ]);

        $this->actingAs($this->customer, 'api');
    }

    #[Test]
    public function adminDashboardReportsCountsAndUserPermissions(): void
    {
        $data = app(PortalController::class)->adminDashboard()->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('Welcome to Admin Portal', $data['message']);
        $this->assertSame(1, $data['data']['total_users']);
        $this->assertSame(1, $data['data']['total_roles']);
        $this->assertSame(1, $data['data']['total_permissions']);
        $this->assertSame(['view_events'], $data['data']['user_permissions']);
    }

    #[Test]
    public function adminDashboardReturnsNoPermissionsForUserWithoutRole(): void
    {
        $this->customer->forceFill(['role_uuid' => null]);
        $this->actingAs($this->customer->setRelation('role', null), 'api');

        $data = app(PortalController::class)->adminDashboard()->getData(true);

        $this->assertSame([], $data['data']['user_permissions']);
    }

    #[Test]
    public function customerPortalReturnsUserPermissions(): void
    {
        $data = app(PortalController::class)->customerPortal()->getData(true);

        $this->assertSame('Welcome to Customer Portal', $data['message']);
        $this->assertSame([], $data['data']['available_events']);
        $this->assertSame(['view_events'], $data['data']['user_permissions']);
    }

    #[Test]
    public function customerPortalReturnsNoPermissionsForUserWithoutRole(): void
    {
        $this->customer->forceFill(['role_uuid' => null]);
        $this->actingAs($this->customer->setRelation('role', null), 'api');

        $data = app(PortalController::class)->customerPortal()->getData(true);

        $this->assertSame([], $data['data']['user_permissions']);
    }

    #[Test]
    public function checkPermissionsReportsAccessFlags(): void
    {
        $data = app(PortalController::class)->checkPermissions(Request::create('/check-permissions'))->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame($this->customer->uuid, $data['data']['user']['uuid']);
        $this->assertSame($this->customer->role_uuid, $data['data']['role']['uuid']);
        $this->assertSame(['view_events'], array_column($data['data']['permissions'], 'code'));
        $this->assertFalse($data['data']['has_admin_access']);
        $this->assertTrue($data['data']['has_customer_access']);
    }

    #[Test]
    public function checkPermissionsReturnsEmptyPermissionsForUserWithoutRole(): void
    {
        $this->customer->forceFill(['role_uuid' => null]);
        $this->actingAs($this->customer->setRelation('role', null), 'api');

        $data = app(PortalController::class)->checkPermissions(Request::create('/check-permissions'))->getData(true);

        $this->assertNull($data['data']['role']);
        $this->assertSame([], $data['data']['permissions']);
        $this->assertFalse($data['data']['has_customer_access']);
    }
}
