<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Helpers\GeneralHelper;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * @params array $rolePermissions
     * @return void
     */
    public function syncPayload(array $rolePermissions)
    {
        $payload = [];
        foreach ($rolePermissions as $rolePermission) {
            $code = $rolePermission['code'];
            $availableAccess = str_split($rolePermission['available_access']);

            // Get all individual permissions for this base code
            $permissions = Permission::where('code', 'like', $code . '-%')->get();

            foreach ($permissions as $permission) {
                $payload[$permission->uuid] = [
                    'access' => $availableAccess,
                    'code' => $permission->code
                ];
            }
        }
        return $payload;
    }

    /**
     * @params array $rolePermissions
     * @return array
     */
    public function accessPayload(array $rolePermissions): array
    {
        $payload = [];
        foreach ($rolePermissions as $key => $permission) {
            $access = GeneralHelper::getScopeAccess($permission['available_access']);
            $code = $permission['code'];
            foreach ($access as $action) {
                $payload[] = [
                    'permission_code' => $code,
                    'action' => $action
                ];
            }
        }
        return $payload;
    }
}
