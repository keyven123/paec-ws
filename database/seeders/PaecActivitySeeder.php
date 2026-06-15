<?php

namespace Database\Seeders;

use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaecActivitySeeder extends Seeder
{
    public function run(): void
    {
        DB::beginTransaction();

        $organization = Organization::where('email', 'inquire@paec.com')->first()
            ?? Organization::where('name', PaecOrganizationSeeder::PAEC_ORG_NAME)->first();

        if (!$organization) {
            $this->call(PaecOrganizationSeeder::class);
            $organization = Organization::where('email', 'inquire@paec.com')->first();
        }

        if (Category::count() === 0) {
            $this->call(CategorySeeder::class);
        }

        $amusementSection = EventSection::firstOrCreate(
            ['name' => EventSection::AMUSEMENT_SECTION],
            [
                'title' => 'Amusements',
                'description' => 'Amusements happening every day',
                'display_order' => 1,
                'is_hidden' => true,
            ]
        );

        $activities = $this->activities();

        foreach ($activities as $activity) {
            $category = Category::where('code', $activity['category_code'])->first();

            $event = Event::updateOrCreate(
                ['slug' => $activity['slug']],
                [
                    'organization_uuid' => $organization->uuid,
                    'category_uuid' => $category?->uuid,
                    'event_section_uuid' => $amusementSection->uuid,
                    'event_name' => $activity['name'],
                    'event_description' => $activity['description'],
                    'contact_email' => 'inquire@paec.com',
                    'address' => $activity['address'],
                    'event_type' => Event::EVENT_TYPES['DAILY'],
                    'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
                    'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
                    'status' => $activity['status'],
                    'published_at' => $activity['status'] === GeneralConstants::EVENT_STATUSES['PUBLISHED']
                        ? now()
                        : null,
                    'is_featured' => $activity['featured'] ?? false,
                    'registration_count' => $activity['views'] ?? 0,
                    'total_revenue' => 0,
                    'ticket_sold' => 0,
                    'total_orders' => 0,
                    'tags' => $activity['tags'] ?? [],
                ]
            );

            $schedule = Schedule::updateOrCreate(
                [
                    'event_uuid' => $event->uuid,
                    'date_from' => $activity['schedule_from'],
                ],
                [
                    'date_to' => $activity['schedule_to'],
                    'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
                ]
            );

            $scheduleTime = ScheduleTime::updateOrCreate(
                [
                    'schedule_uuid' => $schedule->uuid,
                    'time_start' => $activity['time_start'],
                ],
                [
                    'time_end' => $activity['time_end'],
                    'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
                ]
            );

            EventTicket::where('event_uuid', $event->uuid)->forceDelete();

            foreach ($activity['tickets'] as $ticket) {
                EventTicket::create([
                    'event_uuid' => $event->uuid,
                    'schedule_uuid' => $schedule->uuid,
                    'schedule_time_uuid' => $scheduleTime->uuid,
                    'code' => Str::slug($ticket['name'], '_'),
                    'name' => $ticket['name'],
                    'description' => $ticket['name'],
                    'price' => $ticket['price'],
                    'max_ticket' => 500,
                    'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
                ]);
            }
        }

        DB::commit();
    }

    private function activities(): array
    {
        return [
            [
                'slug' => 'bari-the-abandoned-princess',
                'name' => 'BARI – THE ABANDONED PRINCESS',
                'description' => 'Step into a world of wonder with immersive storytelling and unforgettable experiences in Bonifacio Global City.',
                'address' => 'Bonifacio Global City, Taguig',
                'category_code' => 'cultural_heritage',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 1240,
                'schedule_from' => '2026-05-25',
                'schedule_to' => '2026-12-31',
                'time_start' => '10:00:00',
                'time_end' => '21:00:00',
                'tickets' => [['name' => 'General Admission', 'price' => 599]],
                'tags' => ['#theatre', '#immersive', '#bgc'],
            ],
            [
                'slug' => 'neon-arcade-zone',
                'name' => 'Neon Arcade Zone',
                'description' => 'Interactive exhibits, immersive galleries, and hands-on discovery zones designed for curious minds of all ages.',
                'address' => 'Eastwood Mall, Quezon City',
                'category_code' => 'arcade_game_zone',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 892,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '10:00:00',
                'time_end' => '22:00:00',
                'tickets' => [
                    ['name' => '1-Hour Play Pass', 'price' => 499],
                    ['name' => 'All-Day Pass', 'price' => 999],
                ],
                'tags' => ['#arcade', '#gaming', '#neon'],
            ],
            [
                'slug' => 'boogie-bounce-adventure-park',
                'name' => 'Boogie Bounce Adventure Park',
                'description' => 'Jump, climb, and bounce through a massive indoor adventure park packed with trampolines and obstacle courses.',
                'address' => 'SM Mall of Asia, Pasay City',
                'category_code' => 'indoor_playground',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'featured' => true,
                'views' => 2105,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '09:00:00',
                'time_end' => '21:00:00',
                'tickets' => [
                    ['name' => '90-Minute Session', 'price' => 699],
                    ['name' => 'Family Bundle (4 pax)', 'price' => 2399],
                ],
                'tags' => ['#bounce', '#family', '#indoor'],
            ],
            [
                'slug' => 'heritage-walk-manila',
                'name' => 'Heritage Walk Manila',
                'description' => 'Walk through centuries of Philippine history along cobblestone streets and Spanish-era fortifications.',
                'address' => 'Intramuros, Manila',
                'category_code' => 'cultural_heritage',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 743,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '08:00:00',
                'time_end' => '18:00:00',
                'tickets' => [
                    ['name' => 'Guided Walking Tour', 'price' => 350],
                    ['name' => 'Private Group Tour', 'price' => 1200],
                ],
                'tags' => ['#heritage', '#culture', '#walking'],
            ],
            [
                'slug' => 'sky-fun-carnival',
                'name' => 'Sky Fun Carnival',
                'description' => 'A vibrant open-air carnival featuring classic rides, game booths, live entertainment, and street food favorites.',
                'address' => 'BGC, Taguig',
                'category_code' => 'family_entertainment_center',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 1567,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '11:00:00',
                'time_end' => '23:00:00',
                'tickets' => [
                    ['name' => 'Ride All-Day Pass', 'price' => 599],
                    ['name' => 'VIP Fast Lane Pass', 'price' => 899],
                ],
                'tags' => ['#carnival', '#rides', '#family'],
            ],
            [
                'slug' => 'pixel-quest-vr',
                'name' => 'Pixel Quest VR',
                'description' => 'Next-generation virtual reality adventures with multiplayer arenas, escape rooms, and cinematic VR experiences.',
                'address' => 'Ayala Malls Manila Bay',
                'category_code' => 'games',
                'status' => GeneralConstants::EVENT_STATUSES['PENDING'],
                'views' => 312,
                'schedule_from' => '2026-10-10',
                'schedule_to' => '2026-12-31',
                'time_start' => '10:00:00',
                'time_end' => '21:00:00',
                'tickets' => [
                    ['name' => 'Single VR Experience', 'price' => 850],
                    ['name' => '3-Experience Bundle', 'price' => 2100],
                ],
                'tags' => ['#vr', '#gaming', '#tech'],
            ],
            [
                'slug' => 'taste-street-festival',
                'name' => 'Taste Street Festival',
                'description' => 'A curated night market celebrating Filipino street food, global flavors, and local artisan vendors.',
                'address' => 'MOA Complex, Pasay',
                'category_code' => 'food_booths',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 520,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '16:00:00',
                'time_end' => '00:00:00',
                'tickets' => [
                    ['name' => 'Entry Pass', 'price' => 299],
                    ['name' => 'Food Tasting Bundle', 'price' => 599],
                ],
                'tags' => ['#food', '#festival', '#nightmarket'],
            ],
            [
                'slug' => 'mindspark-science-museum',
                'name' => 'MindSpark Science Museum',
                'description' => 'Interactive science exhibits, digital art labs, and hands-on discovery zones for curious minds of all ages.',
                'address' => 'SM Mall of Asia, Pasay City',
                'category_code' => 'museum_educational_attraction',
                'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
                'views' => 743,
                'schedule_from' => '2026-01-01',
                'schedule_to' => '2026-12-31',
                'time_start' => '09:00:00',
                'time_end' => '20:00:00',
                'tickets' => [
                    ['name' => 'General Admission', 'price' => 599],
                    ['name' => 'Priority Access Pass', 'price' => 799],
                ],
                'tags' => ['#science', '#museum', '#family'],
            ],
        ];
    }
}
