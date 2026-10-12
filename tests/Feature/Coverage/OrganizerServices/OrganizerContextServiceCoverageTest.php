<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizer\OrganizerContextService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerContextServiceCoverageTest extends TestCase
{
    use OrganizerServiceFixtures;
    use RefreshDatabase;

    private OrganizerContextService $service;

    private Organization $org;

    private Organization $otherOrg;

    private Role $organizerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrganizerContextService::class);
        $this->org = $this->makeOrg('Context Merchant');
        $this->otherOrg = $this->makeOrg('Context Other');
        $this->organizerRole = $this->makeRole('Org Owner', 'org-owner', $this->org, false);
    }

    #[Test]
    public function organizationUuidIsReturnedForOrganizerAdmin(): void
    {
        $this->loginAdmin($this->org);

        $this->assertSame($this->org->uuid, $this->service->organizationUuidOrAbort());
    }

    #[Test]
    public function organizationContextIsRequired(): void
    {
        $this->assertHttpError(403, 'Organization context is required.', fn () => $this->service->organizationUuidOrAbort());

        $this->loginAdmin(null);

        $this->assertHttpError(403, 'Organization context is required.', fn () => $this->service->organizationUuidOrAbort());
    }

    #[Test]
    public function roleVisibilityAllowsSharedAndOwnRolesOnly(): void
    {
        $this->loginAdmin($this->org);
        $sharedRole = $this->makeRole('Scanner', 'scanner', null, false);
        $platformAdminRole = $this->makeRole('Super Admin', 'super-admin', null, true);
        $customerRole = $this->makeRole('Customer', GeneralConstants::ROLES['CUSTOMER']['name'], null, false);
        $foreignAdminRole = $this->makeRole('Foreign Admin', 'foreign-admin', $this->otherOrg, true);

        $this->service->assertRoleVisibleToOrganizer($sharedRole);
        $this->service->assertRoleVisibleToOrganizer($this->organizerRole);

        foreach ([$platformAdminRole, $customerRole, $foreignAdminRole] as $hidden) {
            $this->assertHttpError(404, 'Role not found.', fn () => $this->service->assertRoleVisibleToOrganizer($hidden));
        }
    }

    #[Test]
    public function onlyOrganizationOwnedRolesCanBeModified(): void
    {
        $this->loginAdmin($this->org);
        $sharedRole = $this->makeRole('Scanner', 'scanner', null, false);

        $this->service->assertRoleOwnedByOrganization($this->organizerRole);

        $this->assertHttpError(
            403,
            'You can only modify roles owned by your organization.',
            fn () => $this->service->assertRoleOwnedByOrganization($sharedRole),
        );
    }

    #[Test]
    public function adminUserMustBelongToOrganization(): void
    {
        $self = $this->loginAdmin($this->org);
        $colleague = $this->makeAdmin($this->org);
        $outsider = $this->makeAdmin($this->otherOrg);

        $this->assertTrue($this->service->assertAdminUserInOrganization($colleague->uuid)->is($colleague));
        $this->assertTrue($this->service->assertAdminUserInOrganization($self->uuid)->is($self));

        $this->assertHttpError(404, 'Admin user not found.', fn () => $this->service->assertAdminUserInOrganization($outsider->uuid));
        $this->assertHttpError(404, 'Admin user not found.', fn () => $this->service->assertAdminUserInOrganization((string) Str::uuid()));
    }

    #[Test]
    public function assignableRoleMustBeVisibleToOrganization(): void
    {
        $this->loginAdmin($this->org);
        $sharedRole = $this->makeRole('Scanner', 'scanner', null, false);
        $platformAdminRole = $this->makeRole('Super Admin', 'super-admin', null, true);

        $this->assertTrue($this->service->assertAssignableRole($this->organizerRole->uuid)->is($this->organizerRole));
        $this->assertTrue($this->service->assertAssignableRole($sharedRole->uuid)->is($sharedRole));

        $this->assertHttpError(
            422,
            'The selected role is not available for your organization.',
            fn () => $this->service->assertAssignableRole($platformAdminRole->uuid),
        );
    }

    #[Test]
    public function customerMustHavePurchasedFromOrganization(): void
    {
        $this->loginAdmin($this->org);
        $customerRole = $this->makeRole('Customer', GeneralConstants::ROLES['CUSTOMER']['name'], null, false);
        $buyer = $this->makeCustomer($customerRole, 'buyer@example.com');
        $stranger = $this->makeCustomer($customerRole, 'stranger@example.com');
        $this->makePaidTx($this->org, null, 100, '2026-05-01 10:00:00', ['user_uuid' => $buyer->uuid]);
        $this->makePaidTx($this->otherOrg, null, 100, '2026-05-01 10:00:00', ['user_uuid' => $stranger->uuid]);

        $this->assertTrue($this->service->assertCustomerVisibleToOrganizer($buyer->uuid)->is($buyer));
        $this->assertHttpError(404, 'Customer not found.', fn () => $this->service->assertCustomerVisibleToOrganizer($stranger->uuid));
    }

    private function makeRole(string $name, string $code, ?Organization $org, bool $isAdmin): Role
    {
        return Role::create([
            'name' => $name,
            'code' => $code,
            'organization_uuid' => $org?->uuid,
            'is_admin' => $isAdmin,
        ]);
    }

    private function makeAdmin(?Organization $org): AdminUser
    {
        return AdminUser::create([
            'role_uuid' => $this->organizerRole->uuid,
            'organization_uuid' => $org?->uuid,
            'email' => Str::random(8) . '@example.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function loginAdmin(?Organization $org): AdminUser
    {
        $admin = $this->makeAdmin($org);
        auth('admin')->login($admin);

        return $admin;
    }

    private function makeCustomer(Role $role, string $email): User
    {
        return User::create([
            'role_uuid' => $role->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function assertHttpError(int $status, string $message, Closure $callback): void
    {
        try {
            $callback();
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame(['success' => false, 'message' => $message], json_decode($response->getContent(), true));

            return;
        }

        $this->fail("Expected HTTP {$status} response exception.");
    }
}
