<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsAdminPermissions;

trait GapAuthMiscFixtures
{
    use GrantsAdminPermissions;

    protected function customerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer', 'is_admin' => false],
        );
    }

    protected function makeCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => 'gap-customer-' . Str::random(8) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function customerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . auth('api')->login($user)];
    }

    /**
     * @param  array<string, list<string>>  $modules
     */
    protected function makeRole(array $modules = [], bool $isAdmin = true, ?string $code = null): Role
    {
        $role = Role::create([
            'name' => 'Gap Role ' . Str::random(4),
            'code' => $code ?? 'gap-role-' . Str::lower(Str::random(8)),
            'is_admin' => $isAdmin,
        ]);

        if ($modules !== []) {
            $this->grantRolePermissions($role, $modules);
        }

        return $role;
    }

    protected function makeAdmin(Role $role, array $attributes = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'role_uuid' => $role->uuid,
            'email' => 'gap-admin-' . Str::random(8) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function adminHeaders(AdminUser $admin): array
    {
        return ['Authorization' => 'Bearer ' . auth('admin')->login($admin)];
    }

    protected function makeOrganization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'name' => 'Gap Merchant ' . Str::random(4),
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Street',
            'contact_number' => '09171234567',
            'email' => 'gap-merchant-' . Str::random(6) . '@test.com',
            'description' => 'Gap merchant',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ], $attributes));
    }
}
