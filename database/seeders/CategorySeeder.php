<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Helpers\CsvHelper;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=CategorySeeder
        DB::beginTransaction();
        $categories = $this->csvToArray(database_path('data/category.csv'));
        foreach ($categories as $category) {
            Category::updateOrCreate($category, $category);
        }
        DB::commit();
    }
}
