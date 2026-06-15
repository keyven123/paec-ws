<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Helpers\GeneralHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Validation\ValidationException;

class RolePermissionSyncService
{
    public function __construct(
        private readonly OrganizerPermissionCatalogService $merchantPartnerCatalog,
    ) {
    }
    /**
     * Sync role permissions from grant payloads (same shape as admin_permissions.csv rows).
     *
     * @param  array<int, array{code: string, available_access: string}>  $grants
     */
    public function syncGrants(Role $role, array $grants): void
    {
        $this->validateGrantsForRole($role, $grants);

        RolePermission::where('role_uuid', $role->uuid)->delete();

        foreach ($grants as $grant) {
            $code = $grant['code'] ?? '';
            $accessString = $grant['available_access'] ?? '';

            if ($code === '' || $accessString === '') {
                continue;
            }

            if ($role->is_admin) {
                $permission = Permission::where('code', $code)->forRoleType(true)->first();
            } else {
                $permission = Permission::where('code', $code)->first();
            }

            if (!$permission) {
                continue;
            }

            foreach (GeneralHelper::getScopeAccess($accessString) as $action) {
                RolePermission::create([
                    'role_uuid' => $role->uuid,
                    'permission_uuid' => $permission->uuid,
                    'access' => $permission->code . '-' . $action,
                ]);
            }
        }
    }

    /**
     * Build grant payloads from stored role_permissions rows.
     *
     * @return array<int, array{code: string, available_access: string}>
     */
    public function grantsFromRole(Role $role): array
    {
        $rows = RolePermission::where('role_uuid', $role->uuid)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $permissionUuids = $rows->pluck('permission_uuid')->unique()->values();
        $permissionsByUuid = Permission::whereIn('uuid', $permissionUuids)->get()->keyBy('uuid');

        $letterByAction = array_flip(GeneralConstants::PERMISSION_LABEL);
        $grantsByCode = [];

        foreach ($rows as $row) {
            $permission = $permissionsByUuid->get($row->permission_uuid);

            if (!$permission) {
                continue;
            }

            $prefix = $permission->code . '-';

            if (!str_starts_with($row->access, $prefix)) {
                continue;
            }

            $action = substr($row->access, strlen($prefix));
            $letter = $letterByAction[$action] ?? null;

            if ($letter === null) {
                continue;
            }

            $grantsByCode[$permission->code] ??= [
                'code' => $permission->code,
                'available_access' => '',
            ];
            $grantsByCode[$permission->code]['available_access'] .= $letter;
        }

        return array_values($grantsByCode);
    }

    /**
     * @param  array<int, array{code: string, available_access?: string}>  $grants
     */
    public function validateGrantsForRole(Role $role, array $grants): void
    {
        if (!$role->is_admin) {
            $this->validateMerchantPartnerGrants($grants);

            return;
        }

        $codes = collect($grants)
            ->pluck('code')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return;
        }

        $allowedCodes = Permission::query()
            ->whereIn('code', $codes)
            ->forRoleType(true)
            ->pluck('code')
            ->all();

        $invalid = array_values(array_diff($codes, $allowedCodes));

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'permission_grants' => [
                    'These permissions are not allowed for Admin roles: ' . implode(', ', $invalid),
                ],
            ]);
        }
    }

    /**
     * @param  array<int, array{code: string, available_access?: string}>  $grants
     */
    private function validateMerchantPartnerGrants(array $grants): void
    {
        $invalidCodes = [];
        $invalidAccess = [];

        foreach ($grants as $grant) {
            $code = $grant['code'] ?? '';
            $accessString = $grant['available_access'] ?? '';

            if ($code === '' || $accessString === '') {
                continue;
            }

            if (!$this->merchantPartnerCatalog->isCodeAllowed($code)) {
                $invalidCodes[] = $code;
                continue;
            }

            if (!$this->merchantPartnerCatalog->isAccessAllowed($code, $accessString)) {
                $invalidAccess[] = $code;
            }
        }

        if ($invalidCodes !== []) {
            throw ValidationException::withMessages([
                'permission_grants' => [
                    'These permissions are not allowed for Merchant Partner roles: '
                    . implode(', ', array_unique($invalidCodes)),
                ],
            ]);
        }

        if ($invalidAccess !== []) {
            throw ValidationException::withMessages([
                'permission_grants' => [
                    'One or more access actions are not allowed for Merchant Partner roles: '
                    . implode(', ', array_unique($invalidAccess)),
                ],
            ]);
        }
    }

    /**
     * @param  array<int, Role>  $roles
     */
    public function appendGrantsToRoles(array $roles): array
    {
        return array_map(function (Role $role) {
            $role->setAttribute('permission_grants', $this->grantsFromRole($role));

            return $role;
        }, $roles);
    }
}
