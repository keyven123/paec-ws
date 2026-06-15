<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VenueSeatNPMusicalSvipSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=VenueSeatNPMusicalSvipSeeder
        DB::beginTransaction();
        $this->command->info('Start Seats Seeder.');

        $this->command->info('Start New Port Musical SVIP Seat Seeder.');
        $seats = $this->csvToArray(database_path('data/new-port-musical-seat-svip.csv'));

        $musicalVenue = Venue::whereCode('newport-musical')->first();
        foreach ($seats as $seat) {
            $venueId = $musicalVenue->uuid;
            $cols = explode('-', $seat['col']);
            $orderStart = $seat['order_start'];
            for ($i = $cols[0]; $i <= $cols[1]; $i++) {
                $data = [
                    'venue_uuid' => $venueId,
                    'col' => $i,
                    'row' => $seat['row'],
                    'seat_no' => $i,
                    'category' => $seat['category'],
                    'color' => $seat['color'],
                    'order' => $orderStart,
                    'status' => $seat['status'],
                ];
                VenueSeat::firstOrCreate($data, $data);
                $orderStart++;
            }
        }
        sleep(1);
        $this->command->info('Success Seeding New Port Musical SVIP Seats.');
        DB::commit();
    }
}
