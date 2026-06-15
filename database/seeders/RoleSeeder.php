<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;
use App\Models\Branch;

class RoleSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=RoleSeeder
        DB::beginTransaction();

        $roles = GeneralConstants::ROLES;
        foreach ($roles as $value) {
            Role::firstOrCreate(
                [
                    'name' => ucwords($value['name']),
                ],
                [
                    'name' => ucwords($value['name']),
                    'code' => $value['name'],
                    'is_admin' => $value['is_admin']
                ]
            );
        }

        DB::commit();
    }
}
