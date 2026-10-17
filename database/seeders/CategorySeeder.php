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
        // to run: php artisan db:seed --class=CategorySeeder
        DB::beginTransaction();
        $categories = $this->csvToArray(database_path('data/category.csv'));
        $keepCodes = [];

        foreach ($categories as $category) {
            $keepCodes[] = $category['code'];
            Category::withTrashed()->updateOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                    'status' => 'active',
                    'deleted_at' => null,
                ],
            );
        }

        Category::query()
            ->whereNotIn('code', $keepCodes)
            ->delete();

        DB::commit();
    }
}
