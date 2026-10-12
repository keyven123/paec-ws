<?php

namespace Tests\Feature\Coverage\GapOrganizer;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerAdminUserControllerCoverageTest extends TestCase
{
    use OrganizerGapFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/organizer/admin-users';

    private Organization $organization;

    private Organization $otherOrganization;

    private AdminUser $admin;

    private Role $ownRole;

    private Role $foreignRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->makeOrganization('Staff Merchant');
        $this->otherOrganization = $this->makeOrganization('Other Staff Merchant');

        $this->ownRole = Role::create([
            'organization_uuid' => $this->organization->uuid,
            'name' => 'Box Office',
            'code' => 'box-office',
            'is_admin' => false,
        ]);
        $this->foreignRole = Role::create([
            'organization_uuid' => $this->otherOrganization->uuid,
            'name' => 'Foreign Staff',
            'code' => 'foreign-staff',
            'is_admin' => true,
        ]);

        $this->admin = $this->loginOrganizerAdmin($this->organization, [
            'admin-users' => ['view', 'create', 'update', 'delete'],
        ]);
    }

    private function makeStaff(Organization $organization, Role $role, string $email, string $firstName = 'Staff'): AdminUser
    {
        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organization->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => $firstName,
            'last_name' => 'Member',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function indexListsOnlyAdminUsersOfTheOrganization(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'own-staff@example.com');
        $this->makeStaff($this->otherOrganization, $this->foreignRole, 'foreign-staff@example.com');

        $response = $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '?per_page=10&role_uuid=' . $this->ownRole->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $staff->uuid)
            ->assertJsonPath('data.0.organization_uuid', $this->organization->uuid)
            ->assertJsonPath('meta.per_page', 10);

        $this->assertStringNotContainsString('foreign-staff@example.com', $response->getContent());
    }

    #[Test]
    public function indexValidatesFilters(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '?status=bogus&role_uuid=not-a-uuid')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'role_uuid']);
    }

    #[Test]
    public function indexRequiresOrganizationContext(): void
    {
        $this->loginOrganizerAdmin(null, ['admin-users' => ['view']]);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE)
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization context is required.');
    }

    #[Test]
    public function indexRequiresViewPermission(): void
    {
        $this->loginOrganizerAdmin($this->organization, ['admin-users' => ['create']]);

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE)
            ->assertForbidden();
    }

    #[Test]
    public function storeCreatesStaffInOrganizationWithVerifiedEmail(): void
    {
        $response = $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'role_uuid' => $this->ownRole->uuid,
                'email' => 'new-staff@example.com',
                'password' => 'secret-pass-1',
                'first_name' => 'New',
                'last_name' => 'Staff',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new-staff@example.com')
            ->assertJsonPath('data.organization_uuid', $this->organization->uuid)
            ->assertJsonPath('data.role.uuid', $this->ownRole->uuid);

        $created = AdminUser::findOrFail($response->json('data.uuid'));
        $this->assertSame($this->organization->uuid, $created->organization_uuid);
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $created->password));
    }

    #[Test]
    public function storeRejectsRoleNotAssignableByOrganization(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'role_uuid' => $this->foreignRole->uuid,
                'email' => 'sneaky@example.com',
                'password' => 'secret-pass-1',
                'first_name' => 'Sneaky',
                'last_name' => 'Staff',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected role is not available for your organization.');

        $this->assertDatabaseMissing('admin_users', ['email' => 'sneaky@example.com']);
    }

    #[Test]
    public function storeRejectsCustomerRoleAndMissingFields(): void
    {
        $this->withHeaders($this->organizerHeaders())
            ->postJson(self::BASE, [
                'role_uuid' => $this->customerRole()->uuid,
                'email' => $this->admin->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role_uuid', 'email', 'password', 'first_name', 'last_name']);
    }

    #[Test]
    public function showReturnsStaffWithRelations(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'shown@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $staff->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $staff->uuid)
            ->assertJsonPath('data.role.code', 'box-office')
            ->assertJsonPath('data.organization.uuid', $this->organization->uuid);
    }

    #[Test]
    public function showReturnsNotFoundForStaffOfAnotherOrganization(): void
    {
        $foreign = $this->makeStaff($this->otherOrganization, $this->foreignRole, 'foreign@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/' . $foreign->uuid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Admin user not found.');
    }

    #[Test]
    public function updateChangesProfileAndRoleButNeverOrganization(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'editable@example.com');
        $newRole = Role::create([
            'organization_uuid' => $this->organization->uuid,
            'name' => 'Scanner Crew',
            'code' => 'scanner-crew',
            'is_admin' => false,
        ]);

        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $staff->uuid, [
                'first_name' => 'Edited',
                'role_uuid' => $newRole->uuid,
                'organization_uuid' => $this->otherOrganization->uuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Edited')
            ->assertJsonPath('data.role.uuid', $newRole->uuid)
            ->assertJsonPath('data.organization_uuid', $this->organization->uuid);

        $this->assertDatabaseHas('admin_users', [
            'uuid' => $staff->uuid,
            'first_name' => 'Edited',
            'role_uuid' => $newRole->uuid,
            'organization_uuid' => $this->organization->uuid,
        ]);
    }

    #[Test]
    public function updateWithoutRoleKeepsExistingRole(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'keeprole@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $staff->uuid, ['last_name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.last_name', 'Renamed')
            ->assertJsonPath('data.role.uuid', $this->ownRole->uuid);
    }

    #[Test]
    public function updateRejectsForeignRole(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'norole@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->putJson(self::BASE . '/' . $staff->uuid, ['role_uuid' => $this->foreignRole->uuid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected role is not available for your organization.');

        $this->assertDatabaseHas('admin_users', ['uuid' => $staff->uuid, 'role_uuid' => $this->ownRole->uuid]);
    }

    #[Test]
    public function destroySoftDeletesStaff(): void
    {
        $staff = $this->makeStaff($this->organization, $this->ownRole, 'deleteme@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $staff->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('admin_users', ['uuid' => $staff->uuid]);
    }

    #[Test]
    public function destroyRejectsStaffOfAnotherOrganization(): void
    {
        $foreign = $this->makeStaff($this->otherOrganization, $this->foreignRole, 'keepme@example.com');

        $this->withHeaders($this->organizerHeaders())
            ->deleteJson(self::BASE . '/' . $foreign->uuid)
            ->assertNotFound();

        $this->assertNotSoftDeleted('admin_users', ['uuid' => $foreign->uuid]);
    }

    #[Test]
    public function availableRolesListsGlobalAndOwnedRolesOnly(): void
    {
        $this->customerRole();

        $response = $this->withHeaders($this->organizerHeaders())
            ->getJson(self::BASE . '/available-roles')
            ->assertOk()
            ->assertJsonPath('success', true);

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertContains('box-office', $codes);
        $this->assertContains(GeneralConstants::ROLES['ORGANIZER']['name'], $codes);
        $this->assertNotContains('foreign-staff', $codes);
        $this->assertNotContains(GeneralConstants::ROLES['CUSTOMER']['name'], $codes);
        $this->assertSame(collect($response->json('data'))->pluck('name')->sort()->values()->all(), collect($response->json('data'))->pluck('name')->all());
    }
}
