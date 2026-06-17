<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SuperAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=AdminUserSeeder

        DB::beginTransaction();
        $role = Role::whereCode(GeneralConstants::ROLES['SUPER_ADMIN']['name'])->first();

        if ($role) {
            AdminUser::where('email', 'admin@ticketoc.com')->delete();

            AdminUser::updateOrCreate(
                ['email' => 'admin@paec.com'],
                [
                    'role_uuid' => $role->uuid,
                    'password' => 'P@ec2026!!',
                    'email_verified_at' => now(),
                    'first_name' => 'Super',
                    'last_name' => 'Admin',
                    'is_first_time_login' => false,
                    'email_verified_at' => now(),
                ]
            );
        }

        DB::commit();
    }
}
