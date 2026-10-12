<?php

namespace Tests\Feature\Coverage\GapAdmin\Concerns;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesGapAdminUsers
{
    /**
     * @param  list<string>  $accesses  e.g. ['finance-view']
     */
    protected function createGapAdmin(array $accesses, bool $isAdmin = true): AdminUser
    {
        $suffix = Str::lower(Str::random(8));

        $role = Role::create([
            'name' => 'Gap Role '.$suffix,
            'code' => $isAdmin ? GeneralConstants::ROLES['ADMIN']['name'] : 'gap_staff_'.$suffix,
            'is_admin' => $isAdmin,
        ]);

        foreach ($accesses as $access) {
            $code = Str::beforeLast($access, '-');
            $permission = Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => Str::headline($code), 'available_access' => ['view', 'create', 'update', 'delete']],
            );

            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => $access,
            ]);
        }

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'gap-admin-'.$suffix.'@test.com',
            'password' => 'password123',
            'first_name' => 'Gap',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    /**
     * The P&L services use MySQL's DATE_FORMAT, which the SQLite test connection lacks.
     */
    protected function registerSqliteDateFormat(): void
    {
        $pdo = DB::connection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }

        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($value, $format) {
            if ($value === null) {
                return null;
            }

            return date(strtr((string) $format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), strtotime((string) $value));
        }, 2);
    }

    /**
     * @return array<string, string>
     */
    protected function gapAdminHeaders(AdminUser $admin): array
    {
        return ['Authorization' => 'Bearer '.auth('admin')->login($admin)];
    }
}
