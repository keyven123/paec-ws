<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerRoleControllerCoverageTest extends TestCase
{
    use OrganizerGapFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/organizer/roles';

    private Organization $organization;

    private Organization $otherOrganization;

    private AdminUser $admin;

    private Role $ownRole;

    private Role $foreignAdminRole;

    private Permission $eventsPermission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->makeOrganization('Role Merchant');
        $this->otherOrganization = $this->makeOrganization('Other Role Merchant');

        $this->admin = $this->loginOrganizerAdmin($this->organization, [
            'roles' => ['view', 'create', 'update', 'delete'],
        ]);

        $this->eventsPermission = Permission::create([
            'name' => 'Events',
            'code' => 'events',
            'available_access' => str_split('rwudx'),
            'role_scope' => 'shared',
        ]);

        $this->ownRole = Role::create([
            'organization_uuid' => $this->organization->uuid,
            'name' => 'Gate Crew',
            'code' => 'gate-crew',
            'is_admin' => false,
        ]);
        $this->foreignAdminRole = Role::create([
            'organization_uuid' => $this->otherOrganization->uuid,
            'name' => 'Foreign Managers',
            'code' => 'foreign-managers',
            'is_admin' => true,
        ]);
    }

    #[Test]
    public function indexListsVisibleRolesWithPermissionGrants(): void
    {
        RolePermission::create([
            'role_uuid' => $this->ownRole->uuid,
            'permission_uuid' => $this->eventsPermission->uuid,
            'access' => 'events-view',
        ]);
        $this->customerRole();

        $response = $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('success', true);

        $roles = collect($response->json('data'))->keyBy('code');
        $this->assertTrue($roles->has('gate-crew'));
        $this->assertFalse($roles->has('foreign-managers'));
        $this->assertFalse($roles->has(GeneralConstants::ROLES['CUSTOMER']['name']));
        $this->assertSame([['code' => 'events', 'available_access' => 'r']], $roles['gate-crew']['permission_grants']);
    }

    #[Test]
    public function indexRequiresOrganizationContext(): void
    {
        $this->loginOrganizerAdmin(null, ['roles' => ['view']]);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE)
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization context is required.');
    }

    #[Test]
    public function showReturnsVisibleRoleWithGrants(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->ownRole->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->ownRole->uuid)
            ->assertJsonPath('data.permission_grants', []);
    }

    #[Test]
    public function showHidesRolesNotVisibleToOrganizer(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $this->foreignAdminRole->uuid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Role not found.');
    }

    #[Test]
    public function updateRenamesRoleAndReplacesLegacyPermissionList(): void
    {
        RolePermission::create([
            'role_uuid' => $this->ownRole->uuid,
            'permission_uuid' => $this->eventsPermission->uuid,
            'access' => 'events-view',
        ]);
        $other = Permission::create([
            'name' => 'Venues',
            'code' => 'venues',
            'available_access' => ['r'],
            'role_scope' => 'shared',
        ]);

        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $this->ownRole->uuid, [
                'name' => 'Gate Crew Leads',
                'code' => 'gate-crew-leads',
                'permissions' => [$other->uuid],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Role updated successfully')
            ->assertJsonPath('data.name', 'Gate Crew Leads')
            ->assertJsonPath('data.code', 'gate-crew-leads');

        $this->assertDatabaseHas('roles', [
            'uuid' => $this->ownRole->uuid,
            'name' => 'Gate Crew Leads',
            'updated_by' => $this->admin->uuid,
        ]);
        $this->assertDatabaseMissing('role_permissions', ['role_uuid' => $this->ownRole->uuid, 'access' => 'events-view']);
        $this->assertDatabaseHas('role_permissions', [
            'role_uuid' => $this->ownRole->uuid,
            'permission_uuid' => $other->uuid,
        ]);
    }

    #[Test]
    public function updateWithoutPermissionsKeepsExistingGrants(): void
    {
        RolePermission::create([
            'role_uuid' => $this->ownRole->uuid,
            'permission_uuid' => $this->eventsPermission->uuid,
            'access' => 'events-view',
        ]);

        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $this->ownRole->uuid, [
                'name' => 'Gate Crew Renamed',
                'code' => 'gate-crew',
            ])
            ->assertOk()
            ->assertJsonPath('data.permission_grants.0.code', 'events');

        $this->assertDatabaseHas('role_permissions', ['role_uuid' => $this->ownRole->uuid, 'access' => 'events-view']);
    }

    #[Test]
    public function updateRejectsRoleOwnedByAnotherOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $this->foreignAdminRole->uuid, [
                'name' => 'Hijacked',
                'code' => 'hijacked',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only modify roles owned by your organization.');

        $this->assertDatabaseHas('roles', ['uuid' => $this->foreignAdminRole->uuid, 'name' => 'Foreign Managers']);
    }

    #[Test]
    public function updateReturnsNotFoundForUnknownRole(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/01a00000-0000-7000-8000-000000000000', [
                'name' => 'Ghost',
                'code' => 'ghost',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function destroyDeletesUnassignedRoleAndItsPermissions(): void
    {
        RolePermission::create([
            'role_uuid' => $this->ownRole->uuid,
            'permission_uuid' => $this->eventsPermission->uuid,
            'access' => 'events-view',
        ]);

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $this->ownRole->uuid)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Role deleted successfully']);

        $this->assertDatabaseMissing('role_permissions', ['role_uuid' => $this->ownRole->uuid]);
        $this->assertNull(Role::find($this->ownRole->uuid));
    }

    #[Test]
    public function destroyRefusesRoleAssignedToAdminUsers(): void
    {
        AdminUser::create([
            'role_uuid' => $this->ownRole->uuid,
            'organization_uuid' => $this->organization->uuid,
            'email' => 'crew@example.com',
            'password' => 'password123',
            'first_name' => 'Crew',
            'last_name' => 'Member',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $this->ownRole->uuid)
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Cannot delete role that is assigned to users']);

        $this->assertNotNull(Role::find($this->ownRole->uuid));
    }

    #[Test]
    public function destroyRefusesRoleAssignedToCustomers(): void
    {
        $customer = $this->makeCustomer('role-holder@example.com');
        $customer->forceFill(['role_uuid' => $this->ownRole->uuid])->save();

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $this->ownRole->uuid)
            ->assertStatus(400);
    }

    #[Test]
    public function destroyRejectsRoleOwnedByAnotherOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $this->foreignAdminRole->uuid)
            ->assertForbidden();

        $this->assertNotNull(Role::find($this->foreignAdminRole->uuid));
    }
}
