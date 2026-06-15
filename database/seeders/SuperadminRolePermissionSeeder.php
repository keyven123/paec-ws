<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;
use App\Models\Permission;

class SuperadminRolePermissionSeeder extends RolePermissionSeeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=SuperadminRolePermissionSeeder
        DB::beginTransaction();
        $this->command->info('Start SUPERADMIN permission sync to roles.');

        $superadminPermissions = $this->csvToArray(database_path('data/superadmin_permissions.csv'));

        $role = Role::where('code', GeneralConstants::ROLES['SUPER_ADMIN']['name'])->first();

        if (!$role) {
            $this->command->error('Superadmin role not found. Run RoleSeeder before SuperadminRolePermissionSeeder.');
            DB::rollBack();

            return;
        }

        $this->syncRolePermissions($role, $superadminPermissions);

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
