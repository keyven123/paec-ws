<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;
use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;

class OrganizerScannerPermissionSeeder extends RolePermissionSeeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=OrganizerScannerPermissionSeeder
        DB::beginTransaction();
        $this->command->info('Start ORGANIZER/SCANNER permission sync to roles.');

        $organizerScannerPermissions = $this->csvToArray(database_path('data/scanner_permissions.csv'));

        $scannerRole = Role::where('code', GeneralConstants::ROLES['SCANNER']['name'])->first();

        if ($scannerRole) {
            $this->syncRolePermissions($scannerRole, $organizerScannerPermissions);
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
