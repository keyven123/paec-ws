<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaecAdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=PaecAdminUserSeeder

        DB::beginTransaction();

        $this->call(PaecOrganizationSeeder::class);

        $role = Role::whereCode(GeneralConstants::ROLES['ORGANIZER']['name'])->first();
        $organization = Organization::where('email', 'inquire@paec.com')->first();

        if ($role && $organization) {
            AdminUser::updateOrCreate(
                ['email' => 'admin@paec.com'],
                [
                    'role_uuid' => $role->uuid,
                    'organization_uuid' => $organization->uuid,
                    'password' => 'P@ec2026!!',
                    'email_verified_at' => now(),
                    'first_name' => 'PAEC',
                    'last_name' => 'Admin',
                    'is_first_time_login' => false,
                    'email_verified_at' => now(),
                ]
            );
        }

        DB::commit();
    }
}
