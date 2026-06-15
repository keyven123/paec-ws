<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Venue;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestEventSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //to run this seeder php artisan db:seed --class=TestEventSeeder
        DB::beginTransaction();

        $venues = Venue::pluck('uuid')->toArray();
        $venues[] = null;

        Event::factory(5)
            ->create(['venue_uuid' => fake()->randomElement($venues)])
            ->each(function ($event) {
                $schedules = Schedule::factory(5)->create(['event_uuid' => $event->uuid]);

                $schedules->each(function ($schedule) use ($event) {
                    $scheduleTimes = ScheduleTime::factory(2)->create(['schedule_uuid' => $schedule->uuid]);

                    $scheduleTimes->each(function ($scheduleTime) use ($event, $schedule) {
                        // Ensure the event has a venue
                        if (is_null($event->venue_uuid)) {
                            $event->venue_uuid = Venue::factory()->create()->uuid;
                            $event->save();
                        }

                        // Ticket sets (from venue or fallback)
                        $tickets = $event->venue
                            ? $event->venue->tickets
                            : [
                                ['name' => 'GOLD', 'price' => 100, 'max_tickets' => 100],
                                ['name' => 'SILVER', 'price' => 50, 'max_tickets' => 50],
                                ['name' => 'BRONZE', 'price' => 25, 'max_tickets' => 25],
                            ];

                        foreach ($tickets as $ticket) {
                            EventTicket::factory()->create([
                                'event_uuid' => $event->uuid,
                                'schedule_uuid' => $schedule->uuid,
                                'schedule_time_uuid' => $scheduleTime->uuid,
                                'code' => strtolower($ticket['name']),
                                'name' => $ticket['name'],
                                'description' => $ticket['name'],
                                'price' => $ticket['price'],
                                'max_ticket' => $ticket['max_tickets'],
                            ]);
                        }
                    });
                });
            });


        DB::commit();
    }
}
