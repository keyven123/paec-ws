<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;
use App\Models\Permission;

class OrganizerRolePermissionSeeder extends RolePermissionSeeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=OrganizerRolePermissionSeeder
        DB::beginTransaction();
        $this->command->info('Start ORGANIZER permission sync to roles.');

        $organizerPermissions = $this->csvToArray(database_path('data/organizer_permissions.csv'));

        $role = Role::where('code', GeneralConstants::ROLES['ORGANIZER']['name'])
            ->first();

        $this->syncRolePermissions($role, $organizerPermissions);

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
