<?php

namespace Database\Seeders;

use App\Helpers\CsvHelper;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SeatBSKSeeder extends Seeder
{
    use CsvHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=SeatBSKSeeder
        DB::beginTransaction();
        $this->command->info('Start Seats Seeder.');

        $this->command->info('Start BSK Seat Seeder.');
        $seats = $this->csvToArray(database_path('data/bsk-seat.csv'));

        $bskVenue = Venue::whereCode('bsk-club')->first();
        foreach ($seats as $seat) {
            $venueId = $bskVenue->uuid;
            $cols = explode('-', $seat['col']);
            $start = (int) $cols[0];
            $end = isset($cols[1]) && $cols[1] !== '' ? (int) $cols[1] : $start;
            $orderStart = $seat['order_start'];
            for ($i = $start; $i <= $end; $i++) {
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
        $this->command->info('Success Seeding BSK Seats.');
        DB::commit();
    }
}
