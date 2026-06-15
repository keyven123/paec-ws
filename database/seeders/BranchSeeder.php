<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\Branch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BranchSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=BranchSeeder

        DB::beginTransaction();
        $branches = $this->csvToArray(database_path('data/branch.csv'));
        foreach ($branches as $branch) {
            Branch::firstOrCreate(
                [
                    'name' => $branch['name'],
                ],
                [
                    'name' => $branch['name'],
                    'code' => $branch['code']
                ]
            );
        }

        DB::commit();
    }
}
