<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\EventSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class EventSectionSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=EventSectionSeeder
        DB::beginTransaction();
        $eventSections = $this->csvToArray(database_path('data/event_section.csv'));
        foreach ($eventSections as $eventSection) {
            EventSection::firstOrCreate($eventSection);
        }
        DB::commit();
    }
}
