<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;

trait GrantsAdminPermissions
{
    /**
     * Attach module permissions to an admin role (creates Permission rows as needed).
     *
     * @param  array<string, list<string>>  $modules  e.g. ['organizations' => ['view', 'update']]
     */
    protected function grantRolePermissions(Role $role, array $modules): void
    {
        foreach ($modules as $code => $accesses) {
            $permission = Permission::create([
                'name' => ucwords(str_replace('-', ' ', $code)),
                'code' => $code,
                'available_access' => $accesses,
                'role_scope' => 'admin',
            ]);

            foreach ($accesses as $access) {
                RolePermission::create([
                    'role_uuid' => $role->uuid,
                    'permission_uuid' => $permission->uuid,
                    'access' => $code . '-' . $access,
                ]);
            }
        }
    }

    /**
     * Permissions for merchant partner org profile, commission %, and payment method toggles.
     */
    protected function grantMerchantPartnerAdminPermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'organizations' => ['view', 'update'],
            'commissions' => ['view', 'update'],
            'payment-methods' => ['view', 'update'],
        ]);
    }

    /**
     * Permissions for affiliate partner list, stats, suspend/reinstate.
     */
    protected function grantAffiliatePartnerAdminPermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'affiliate-partners' => ['view', 'update'],
        ]);
    }

    /**
     * Permissions for affiliate payout request review and approval.
     */
    protected function grantAffiliatePayoutAdminPermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'affiliate-payouts' => ['view', 'update'],
        ]);
    }

    /**
     * Permissions for per-event affiliate commission settings (non-Fun events).
     */
    protected function grantAffiliateEventsAdminPermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'affiliate-events' => ['view', 'update'],
        ]);
    }

    protected function grantCommissionsHistoryAdminPermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'commissions' => ['view'],
        ]);
    }

    /**
     * Permissions for organizer profile and organization bank accounts.
     */
    protected function grantOrganizerProfilePermissions(Role $role): void
    {
        $this->grantRolePermissions($role, [
            'profile' => ['view', 'update'],
        ]);
    }
}
