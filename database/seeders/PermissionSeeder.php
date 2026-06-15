<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=PermissionSeeder
        DB::beginTransaction();
        $this->command->info('Removing existing permission that are not found on file.');
        $newPermissions = $this->csvToArray(database_path('data/permissions.csv'));
        $newCodes = collect($newPermissions)->pluck('code')->toArray();

        // Remove old permissions that are not in the new list
        Permission::all()->map(function ($permission) use ($newCodes) {
            $baseCode = $this->getBaseCodeFromPermission($permission['code']);
            if (!in_array($baseCode, $newCodes)) {
                // Remove from role_permissions table
                \App\Models\RolePermission::where('permission_uuid', $permission->uuid)->delete();
                $permission->delete();
            }
        });

        $this->command->info('Adding or updating permissions.');
        foreach ($newPermissions as $permission) {
            $code = $permission['code'];
            $name = $permission['name'];
            $availableAccessArray = str_split($permission['available_access']);

            Permission::updateOrCreate(
                [
                    'code' => $code,
                ],
                [
                    'name' => $name,
                    'code' => $code,
                    'module' => $permission['module'] ?? 'Other Module',
                    'available_access' => $availableAccessArray,
                    'role_scope' => $permission['role_scope'] ?? 'admin',
                    'description' => $permission['description'] ?? null,
                ]
            );
        }
        DB::commit();
    }

    private function getBaseCodeFromPermission(string $permissionCode): string
    {
        $parts = explode('-', $permissionCode);
        return $parts[0];
    }
}
