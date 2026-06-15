<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Upload;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MigrateEvents extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::beginTransaction();
        $events = $this->csvToArray(app_path('Console/data/events.csv'));
        $othersCategory = Category::where('code', 'others')->first();
        foreach ($events as $event) {
            $eventSection = EventSection::where('name', $event['event_section'])->first();
            $venue = Venue::where('code', $event['venue'])->first();
            $category = Category::where('code', 'LIKE', '%' . $event['category'] . '%')->first();
            $portrait = Upload::create([
                'type' => 'image',
                'mime_type' => 'image/' . substr(strrchr($event['portrait_image'], '.'), 1),
                'extension' => substr(strrchr($event['portrait_image'], '.'), 1),
                'disk' => 'digitalocean',
                'path' => $event['portrait_image'],
            ]);
            $featured = Upload::create([
                'type' => 'image',
                'mime_type' => 'image/' . substr(strrchr($event['featured_image'], '.'), 1),
                'extension' => substr(strrchr($event['featured_image'], '.'), 1),
                'disk' => 'digitalocean',
                'path' => $event['featured_image'],
            ]);

            $organization = Organization::where('email', $event['organization_email'])->first();

            Event::create([
                'venue_uuid' => $venue->uuid ?? null,
                'category_uuid' => $category ? $category->uuid : $othersCategory->uuid,
                'event_section_uuid' => $eventSection->uuid,
                'organization_uuid' => $organization->uuid ?? null,
                'event_name' => $event['event_name'],
                'contact_email' => $event['contact_email'],
                'total_revenue' => $event['total_revenue'],
                'ticket_sold' => $event['ticket_sold'] ?? 0,
                'total_orders' => $event['total_orders'] ?? 0,
                'portrait_image_uuid' => $portrait->uuid,
                'featured_image_uuid' => $featured->uuid,
                'event_config' => $event['has_seats'] == 'TRUE' ? Event::EVENT_CONFIGS['SEAT_SELECTION'] : Event::EVENT_CONFIGS['OPEN_TICKET'],
                'event_type' => Event::SCHEDULE_TYPES['SINGLE'],
                'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
                'excluded_dates' => $event['excluded_dates'],
                'published_at' => Carbon::parse($event['published_at'])->toDateTimeString(),
                'approved_at' => Carbon::parse($event['approved_at'])->toDateTimeString(),
                'is_featured' => $event['is_featured'] == 'TRUE' ? true : false,
                'featured_order' => $event['featured_order'],
                'featured_from' => Carbon::parse($event['featured_from'])->toDateTimeString(),
                'featured_until' => Carbon::parse($event['featured_until'])->toDateTimeString(),
                'meta_title' => $event['meta_title'],
                'meta_description' => $event['meta_description'],
                'tags' => $event['tags'],
                'track_event_meta' => $event['track_event_meta'] == 'TRUE' ? true : false,
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            ]);
            $this->info('Created event: ' . $event['event_name']);
        }

        $eventSchedules = $this->csvToArray(app_path('Console/data/event_schedules.csv'));

        foreach ($eventSchedules as $eventSchedule) {
            $event = Event::where('event_name', 'LIKE', '%' . $eventSchedule['event_name'] . '%')->first();
            if (!$event) {
                $this->error('Event not found: ' . $eventSchedule['event_name']);
                continue;
            }
            $sched = Schedule::create([
                'event_uuid' => $event->uuid,
                'date_from' => Carbon::parse($eventSchedule['date_from'])->format('Y-m-d'),
                'date_to' => Carbon::parse($eventSchedule['date_to'])->format('Y-m-d'),
                'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
            ]);
            ScheduleTime::create([
                'schedule_uuid' => $sched->uuid,
                'time_start' => $eventSchedule['time_start'],
                'time_end' => $eventSchedule['time_end'],
            ]);
            // if ($event->venue_uuid) {
            //     $venue = Venue::where('uuid', $event->venue_uuid)->first();
            //     foreach ($venue->tickets as $key => $ticket) {
            //         EventTicket::create([
            //             'event_uuid' => $event->uuid,
            //             'schedule_uuid' => $sched->uuid,
            //             'schedule_time_uuid' => $time->uuid,
            //             'code' => strtolower($ticket['name']),
            //             'name' => $ticket['name'],
            //             'price' => $ticket['price'],
            //             'max_ticket' => $ticket['max_tickets'],
            //         ]);
            //     }
            // }
            $this->info('Created schedule: ' . $eventSchedule['event_name']);
            $this->info('Created schedule time: ' . $eventSchedule['time_start'] . ' - ' . $eventSchedule['time_end']);
        }

        DB::commit();
    }
}
