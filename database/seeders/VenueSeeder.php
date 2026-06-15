<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Helpers\CsvHelper;
use App\Models\Place;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class VenueSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=VenueSeeder
        DB::beginTransaction();
        $this->command->info('Start Place Seeder.');
        $places = $this->csvToArray(database_path('data/place.csv'));

        foreach ($places as $place) {
            Place::firstOrCreate([
                'name' => $place['name'],
                'code' => $place['code'],
            ], [
                'address' => $place['address'],
                'image_url' => $place['image_url'],
                'is_visible' => $place['is_visible'] === 'true' ? true : false,
            ]);
        }

        $this->command->info('Start Venue Seeder.');
        $venues = $this->csvToArray(database_path('data/venue.csv'));

        $ticketsMusical = [
            [
                'name' => 'SVIP',
                'max_tickets' => 343
            ],
            [
                'name' => 'VIP',
                'max_tickets' => 375
            ],
            [
                'name' => 'GOLD',
                'max_tickets' => 325
            ],
            [
                'name' => 'SILVER',
                'max_tickets' => 541
            ],
            [
                'name' => 'BRONZE',
                'max_tickets' => 126
            ],
        ];

        $ticketsTheater = [
            [
                'name' => 'PLATINUM',
                'max_tickets' => 154
            ],
            [
                'name' => 'SVIP',
                'max_tickets' => 326
            ],
            [
                'name' => 'VIP',
                'max_tickets' => 311
            ],
            [
                'name' => 'GOLD',
                'max_tickets' => 402
            ],
            [
                'name' => 'SILVER',
                'max_tickets' => 453
            ],
            [
                'name' => 'BRONZE',
                'max_tickets' => 64
            ],
        ];

        $newport = Place::whereCode(GeneralConstants::PLACES['NEWPORT'])->first();

        foreach ($venues as $venue) {
            if ($venue['code'] === 'newport-musical') {
                $tickets = $ticketsMusical;
            }
            if ($venue['code'] === 'newport-theater') {
                $tickets = $ticketsTheater;
            }
            Venue::updateOrCreate($venue, array_merge($venue, ['tickets' => $tickets, 'place_uuid' => $newport->uuid]));
        }

        $na = Place::whereCode(GeneralConstants::PLACES['NA'])->first();

        Venue::updateOrCreate([
            'place_uuid' => $na->uuid,
        ], [
            'name' => 'NA',
            'code' => 'na',
            'type' => 'others',
        ]);

        $this->command->info('Success Seeding Venue.');
        DB::commit();
    }
}
