<?php

namespace Tests\Feature\Coverage\RModelsOther;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\PasswordReset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserAffiliate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccessControlModelsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function makeRole(string $code, bool $isAdmin = true, ?string $organizationUuid = null): Role
    {
        return Role::create([
            'name' => ucfirst($code),
            'code' => $code,
            'is_admin' => $isAdmin,
            'organization_uuid' => $organizationUuid,
        ]);
    }

    private function makePermission(string $code): Permission
    {
        return Permission::create([
            'name' => ucfirst($code),
            'code' => $code,
            'available_access' => ['view', 'update'],
            'role_scope' => 'admin',
        ]);
    }

    private function grant(Role $role, Permission $permission, string $access): RolePermission
    {
        return RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => $access,
        ]);
    }

    private function makeAdmin(Role $role, array $overrides = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'role_uuid' => $role->uuid,
            'email' => fake()->unique()->safeEmail(),
            'password' => 'secret-password',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ], $overrides));
    }

    private function makeUser(Role $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role_uuid' => $role->uuid], $overrides));
    }

    #[Test]
    public function adminUserPermissionsRelationReturnsRolePermissions(): void
    {
        $role = $this->makeRole('admin');
        $permission = $this->makePermission('users');
        $this->grant($role, $permission, 'users-view');
        $admin = $this->makeAdmin($role);

        $this->assertSame([$permission->uuid], $admin->permissions()->pluck('permissions.uuid')->all());
    }

    #[Test]
    public function adminUserPasswordResetsRelationReturnsMorphedResets(): void
    {
        $admin = $this->makeAdmin($this->makeRole('admin'));
        $reset = PasswordReset::create([
            'email' => $admin->email,
            'resettable_type' => AdminUser::class,
            'resettable_id' => $admin->uuid,
        ]);

        $this->assertSame([$reset->uuid], $admin->passwordResets()->pluck('uuid')->all());
    }

    #[Test]
    public function adminUserWithoutRoleHasNoPermissionsAndIsNotAdmin(): void
    {
        $admin = new AdminUser();

        $this->assertFalse($admin->hasPermission('users-view'));
        $this->assertFalse($admin->hasAnyPermission(['users-view']));
        $this->assertFalse($admin->isAdmin());
    }

    #[Test]
    public function adminUserHasPermissionByBasePermissionCode(): void
    {
        $role = $this->makeRole('admin');
        $this->grant($role, $this->makePermission('users'), 'users-view');
        $this->makePermission('events');
        $admin = $this->makeAdmin($role);

        $this->assertTrue($admin->hasPermission('users'));
        $this->assertFalse($admin->hasPermission('events'));
    }

    #[Test]
    public function adminUserHasAnyPermissionMatchesAccessCodes(): void
    {
        $role = $this->makeRole('admin');
        $this->grant($role, $this->makePermission('users'), 'users-view');
        $admin = $this->makeAdmin($role);

        $this->assertTrue($admin->hasAnyPermission(['events-view', 'users-view']));
        $this->assertFalse($admin->hasAnyPermission(['events-view', 'users-delete']));
    }

    #[Test]
    public function adminUserFiltersScopeSearchesByEmailAndFullName(): void
    {
        $role = $this->makeRole('admin');
        $byEmail = $this->makeAdmin($role, ['email' => 'unique-match@paec.test']);
        $byName = $this->makeAdmin($role, ['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $this->makeAdmin($role, ['first_name' => 'Other', 'last_name' => 'Person']);

        $emailHits = AdminUser::filters(['q' => 'unique-match'])->pluck('uuid')->all();
        $nameHits = AdminUser::filters(['q' => 'Grace Hopper'])->pluck('uuid')->all();

        $this->assertSame([$byEmail->uuid], $emailHits);
        $this->assertSame([$byName->uuid], $nameHits);
    }

    #[Test]
    public function adminUserFiltersScopeFiltersByStatusAndAdminScope(): void
    {
        $role = $this->makeRole('admin');
        $organization = Organization::factory()->create();
        $platformAdmin = $this->makeAdmin($role, ['status' => 'active']);
        $merchantAdmin = $this->makeAdmin($role, ['status' => 'inactive', 'organization_uuid' => $organization->uuid]);
        $customerRole = $this->makeRole(GeneralConstants::ROLES['CUSTOMER']['name'], false);
        $this->makeAdmin($customerRole, ['status' => 'active']);

        $this->assertSame([$platformAdmin->uuid], AdminUser::filters(['status' => 'active'])->pluck('uuid')->all());
        $this->assertSame([$platformAdmin->uuid], AdminUser::filters(['is_admin' => true])->pluck('uuid')->all());
        $this->assertSame([$merchantAdmin->uuid], AdminUser::filters(['is_admin' => false])->pluck('uuid')->all());
    }

    #[Test]
    public function userPermissionsAndPasswordResetsRelations(): void
    {
        $role = $this->makeRole('organizer', false);
        $permission = $this->makePermission('tickets');
        $this->grant($role, $permission, 'tickets-view');
        $user = $this->makeUser($role);
        $reset = PasswordReset::create([
            'email' => $user->email,
            'resettable_type' => User::class,
            'resettable_id' => $user->uuid,
        ]);

        $this->assertSame([$permission->uuid], $user->permissions()->pluck('permissions.uuid')->all());
        $this->assertSame([$reset->uuid], $user->passwordResets()->pluck('uuid')->all());
    }

    #[Test]
    public function userHasPermissionChecksAccessCodeOnRole(): void
    {
        $role = $this->makeRole('organizer', false);
        $this->grant($role, $this->makePermission('tickets'), 'tickets-view');
        $user = $this->makeUser($role);

        $this->assertTrue($user->hasPermission('tickets-view'));
        $this->assertFalse($user->hasPermission('tickets-delete'));
    }

    #[Test]
    public function userWithoutRoleHasNoAnyPermission(): void
    {
        $this->assertFalse((new User())->hasAnyPermission(['tickets-view']));
    }

    #[Test]
    public function userFiltersScopeSearchesByEmailNameAndStatus(): void
    {
        $role = $this->makeRole('customer', false);
        $byEmail = $this->makeUser($role, ['email' => 'needle@paec.test', 'status' => 'active']);
        $byName = $this->makeUser($role, ['first_name' => 'Linus', 'last_name' => 'Torvalds', 'status' => 'inactive']);

        $this->assertSame([$byEmail->uuid], User::filters(['q' => 'needle'])->pluck('uuid')->all());
        $this->assertSame([$byName->uuid], User::filters(['q' => 'Linus Torvalds'])->pluck('uuid')->all());
        $this->assertSame([$byName->uuid], User::filters(['status' => 'inactive'])->pluck('uuid')->all());
    }

    #[Test]
    public function userFiltersScopeFiltersByRoleAndAffiliateStatus(): void
    {
        $customerRole = $this->makeRole('customer', false);
        $organizerRole = $this->makeRole('organizer', false);
        $customer = $this->makeUser($customerRole);
        $organizer = $this->makeUser($organizerRole);
        UserAffiliate::create(['user_uuid' => $customer->uuid, 'affiliate_status' => 'approved']);
        UserAffiliate::create(['user_uuid' => $organizer->uuid, 'affiliate_status' => 'pending']);

        $this->assertSame([$organizer->uuid], User::filters(['role_id' => $organizerRole->uuid])->pluck('uuid')->all());
        $this->assertSame([$customer->uuid], User::filters(['affiliate_status' => 'approved'])->pluck('uuid')->all());
    }

    #[Test]
    public function roleRevokePermissionToRemovesPermissionFromRole(): void
    {
        $role = $this->makeRole('admin');
        $this->grant($role, $this->makePermission('users'), 'users-view');

        $role->revokePermissionTo('users');

        $this->assertSame(0, $role->permissions()->count());
    }

    #[Test]
    public function roleRevokePermissionToIgnoresUnknownPermission(): void
    {
        $role = $this->makeRole('admin');
        $this->grant($role, $this->makePermission('users'), 'users-view');

        $role->revokePermissionTo('does-not-exist');

        $this->assertSame(1, $role->permissions()->count());
    }

    #[Test]
    public function roleFiltersScopeFiltersByKeywordAdminFlagAndOrganization(): void
    {
        $organization = Organization::factory()->create();
        $finance = $this->makeRole('finance');
        $merchant = $this->makeRole('merchant_staff', false, $organization->uuid);

        $this->assertSame([$finance->uuid], Role::filters(['q' => 'finan'])->pluck('uuid')->all());
        $this->assertSame([$merchant->uuid], Role::filters(['is_admin' => false])->pluck('uuid')->all());
        $this->assertSame(
            [$merchant->uuid],
            Role::filters(['organization_uuid' => $organization->uuid])->pluck('uuid')->all()
        );
    }

    #[Test]
    public function permissionRolesRelationAndRolePermissionRoleRelation(): void
    {
        $role = $this->makeRole('admin');
        $permission = $this->makePermission('users');
        $pivot = $this->grant($role, $permission, 'users-view');

        $this->assertSame([$role->uuid], $permission->roles()->pluck('roles.uuid')->all());
        $this->assertTrue($pivot->role->is($role));
    }

    #[Test]
    public function permissionSyncRolePermissionsFailsBecausePivotUuidIsNotGenerated(): void
    {
        $role = $this->makeRole('admin');
        $permission = $this->makePermission('users');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('role_permissions.uuid');

        $permission->syncRolePermissions($role, ['access' => 'users-view']);
    }
}
