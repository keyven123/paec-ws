<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\Place;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class VenueBSKSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=VenueBSKSeeder
        DB::beginTransaction();
        $this->command->info('Start BSK place Seeder.');

        $bskClub = Place::firstOrCreate([
            'name' => "BSK Club Manila",
            'code' => "bsk-club",
        ], [
            'address' => "Greenfield District Mandaluyong Philippines 1550",
            'image_url' => "public/images/places/bsk-club.jpg",
            'is_visible' => true,
        ]);

        $this->command->info('Start BSK Venue Seeder.');

        $seats = [
            [
                'name' => 'SKY',
                'max_tickets' => 2
            ],
            [
                'name' => 'ORBIT',
                'max_tickets' => 4
            ],
            [
                'name' => 'LEFT WING 2-6',
                'max_tickets' => 5
            ],
            [
                'name' => 'LEFT WING 1 & 7',
                'max_tickets' => 2
            ],
            [
                'name' => 'RIGHT WING 2-6',
                'max_tickets' => 5
            ],
            [
                'name' => 'RIGHT WING 1 & 7',
                'max_tickets' => 2
            ],
            [
                'name' => 'COCKPIT',
                'max_tickets' => 16
            ],
        ];

        Venue::updateOrCreate([
            'place_uuid' => $bskClub->uuid,
        ], [
            'name' => 'BSK Club Manila',
            'code' => 'bsk-club',
            'type' => 'club',
            'tickets' => $seats,
        ]);


        $this->command->info('Success Seeding BSK Venue.');
        DB::commit();
    }
}
