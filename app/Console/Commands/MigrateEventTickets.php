<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Support\Facades\DB;

class MigrateEventTickets extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-event-tickets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate event tickets';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::beginTransaction();
        $ticketTypes = $this->csvToArray(app_path('Console/data/ticket_types.csv'));
        $events = $this->csvToArray(app_path('Console/data/events.csv'));
        foreach ($ticketTypes as $ticketType) {
            #search event by name
            $searchEvent = collect($events)->firstWhere('uuid', $ticketType['event_uuid']);
            $event = $searchEvent ? Event::where('event_name', 'LIKE', '%' . $searchEvent['event_name'] . '%')->first() : null;
            if (!$event) {
                continue;
            }
            $schedule = Schedule::where('event_uuid', $event->uuid)->first();
            $scheduleTime = ScheduleTime::where('schedule_uuid', $schedule->uuid)->first();
            $ticketType = EventTicket::create([
                'event_uuid' => $event->uuid,
                'schedule_uuid' => $schedule->uuid,
                'schedule_time_uuid' => $scheduleTime->uuid,
                'code' => strtolower(str_replace(' ', '-', str_replace('-', ' ', $ticketType['name']))),
                'name' => $ticketType['name'],
                'description' => $ticketType['description'],
                'price' => $ticketType['price'],
                'max_ticket' => $ticketType['max_ticket'] ?? 0,
                'available_from' => $ticketType['available_from'] ?? null,
                'available_to' => $ticketType['available_to'] ?? null,
                'display_order' => $ticketType['display_order'] != '' || $ticketType['display_order'] != null ? $ticketType['display_order'] : 1,
            ]);
            $this->info('Ticket type migrated: ' . $ticketType['name']);
        }
        DB::commit();
    }
}
