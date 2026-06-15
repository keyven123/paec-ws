<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use Database\Seeders\AdminRolePermissionSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerRolePermissionSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\PaecOrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Database\Seeders\SuperadminRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseByCityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            SuperadminRolePermissionSeeder::class,
            AdminRolePermissionSeeder::class,
            CustomerRolePermissionSeeder::class,
            SuperAdminUserSeeder::class,
            CustomerSeeder::class,
            PaecOrganizationSeeder::class,
            CategorySeeder::class,
        ]);
    }

    public function test_public_browse_by_city_returns_location_cards(): void
    {
        ['event' => $event, 'taguig' => $taguig, 'manila' => $manila] = $this->createMultiLocationEvent();

        $response = $this->getJson('/api/v1/public/events/browse-by-city?type=amusements');

        $response->assertOk()
            ->assertJsonPath('data.cities.0', 'Manila')
            ->assertJsonPath('data.cities.1', 'Taguig')
            ->assertJsonFragment([
                'uuid' => $taguig->uuid,
                'city' => 'Taguig',
                'label' => 'BGC Branch',
                'event_uuid' => $event->uuid,
            ])
            ->assertJsonFragment([
                'uuid' => $manila->uuid,
                'city' => 'Manila',
                'label' => 'Manila Branch',
                'event_uuid' => $event->uuid,
            ]);
    }

    public function test_public_browse_by_city_can_filter_by_city(): void
    {
        $this->createMultiLocationEvent();

        $response = $this->getJson('/api/v1/public/events/browse-by-city?type=amusements&city=Taguig');

        $response->assertOk()
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonPath('data.locations.0.city', 'Taguig')
            ->assertJsonPath('data.locations.0.label', 'BGC Branch');
    }

    public function test_public_browse_by_city_excludes_inactive_locations(): void
    {
        ['taguig' => $taguig] = $this->createMultiLocationEvent();

        $taguig->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/events/browse-by-city?type=amusements&city=Taguig');

        $response->assertOk()
            ->assertJsonCount(0, 'data.locations');
    }

    /**
     * @return array{event: Event, ticket: EventTicket, taguig: EventLocation, manila: EventLocation}
     */
    private function createMultiLocationEvent(): array
    {
        ['event' => $event, 'ticket' => $ticket] = $this->createPublishedEvent();

        $organization = Organization::where('email', 'inquire@paec.com')->firstOrFail();

        $taguig = EventLocation::create([
            'event_uuid' => $event->uuid,
            'name' => 'BGC Branch',
            'city' => 'Taguig',
            'address' => 'Bonifacio Global City, Taguig',
            'organization_uuid' => $organization->uuid,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $manila = EventLocation::create([
            'event_uuid' => $event->uuid,
            'name' => 'Manila Branch',
            'city' => 'Manila',
            'address' => 'Intramuros, Manila',
            'organization_uuid' => $organization->uuid,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return compact('event', 'ticket', 'taguig', 'manila');
    }

    /**
     * @return array{event: Event, ticket: EventTicket}
     */
    private function createPublishedEvent(): array
    {
        $organization = Organization::where('email', 'inquire@paec.com')->firstOrFail();
        $category = Category::query()->firstOrFail();

        $section = EventSection::firstOrCreate(
            ['name' => EventSection::AMUSEMENT_SECTION],
            [
                'title' => 'Amusements',
                'description' => 'Amusements happening every day',
                'display_order' => 1,
                'is_hidden' => true,
            ]
        );

        $event = Event::create([
            'organization_uuid' => $organization->uuid,
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $section->uuid,
            'event_name' => 'Test Multi-Location Attraction',
            'event_description' => 'Location-aware test activity',
            'contact_email' => 'inquire@paec.com',
            'address' => 'Bonifacio Global City, Taguig',
            'city' => 'Taguig',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'published_at' => now(),
            'slug' => 'test-multi-location-' . uniqid(),
        ]);

        $ticket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'general_admission',
            'name' => 'General Admission',
            'description' => 'General Admission',
            'price' => 599,
            'max_ticket' => 500,
            'visit_policy' => 'priority',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        return compact('event', 'ticket');
    }
}
