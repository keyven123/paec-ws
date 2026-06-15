<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Branch;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;

class AdminRolePermissionSeeder extends RolePermissionSeeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=AdminRolePermissionSeeder
        DB::beginTransaction();
        $this->command->info('Start ADMIN permission sync to roles.');

        $adminPermissions = $this->csvToArray(database_path('data/admin_permissions.csv'));

        $role = Role::where('code', GeneralConstants::ROLES['ADMIN']['name'])->first();

        if ($role) {
            $this->syncRolePermissions($role, $adminPermissions);
        }

        DB::commit();
    }

    private function syncRolePermissions(Role $role, array $permissions): void
    {
        // Remove existing permissions
        RolePermission::where('role_uuid', $role->uuid)->delete();

        // Get all permission assignments that should be assigned to this role
        $permissionAssignments = $this->accessPayload($permissions);

        // Assign permissions to role
        foreach ($permissionAssignments as $assignment) {
            $permission = Permission::where('code', $assignment['permission_code'])->first();

            if ($permission) {
                RolePermission::create([
                    'role_uuid' => $role->uuid,
                    'permission_uuid' => $permission->uuid,
                    'access' => $permission->code . '-' . $assignment['action']
                ]);
            }
        }
    }
}
