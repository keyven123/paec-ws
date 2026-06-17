<?php

namespace Tests\Support\Concerns;

use Database\Seeders\AdminRolePermissionSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerRolePermissionSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\DatasetSeeder;
use Database\Seeders\EventSectionSeeder;
use Database\Seeders\OrganizerRolePermissionSeeder;
use Database\Seeders\PaecAdminUserSeeder;
use Database\Seeders\PaecOrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Database\Seeders\SuperadminRolePermissionSeeder;
use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;

trait AuthenticatesApiUsers
{
    protected ?string $adminToken = null;

    protected ?string $superAdminToken = null;

    protected ?string $customerToken = null;

    protected function seedApiDatabase(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            SuperadminRolePermissionSeeder::class,
            AdminRolePermissionSeeder::class,
            OrganizerRolePermissionSeeder::class,
            CustomerRolePermissionSeeder::class,
            SuperAdminUserSeeder::class,
            CustomerSeeder::class,
            PaecOrganizationSeeder::class,
            PaecAdminUserSeeder::class,
            CategorySeeder::class,
            EventSectionSeeder::class,
            DatasetSeeder::class,
        ]);
    }

    protected function authenticateAdmin(): string
    {
        if ($this->adminToken) {
            return $this->adminToken;
        }

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@paec.com',
            'password' => 'P@ec2026!!',
        ]);

        $response->assertOk();
        $this->adminToken = $response->json('access_token');

        return $this->adminToken;
    }

    protected function authenticateSuperAdmin(): string
    {
        if ($this->superAdminToken) {
            return $this->superAdminToken;
        }

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@ticketoc.com',
            'password' => '123ticketoc$$$',
        ]);

        $response->assertOk();
        $this->superAdminToken = $response->json('access_token');

        return $this->superAdminToken;
    }

    protected function authenticateCustomer(): string
    {
        if ($this->customerToken) {
            return $this->customerToken;
        }

        $response = $this->postJson('/api/v1/login', [
            'email' => 'customer@paec.com',
            'password' => 'P@ec2026!!',
        ]);

        $response->assertOk();
        $this->customerToken = $response->json('access_token');

        return $this->customerToken;
    }

    protected function createPublishedEventForTests(): Event
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
            ],
        );

        $event = Event::create([
            'organization_uuid' => $organization->uuid,
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $section->uuid,
            'event_name' => 'API Test Activity',
            'event_description' => 'Published activity for API smoke tests',
            'contact_email' => 'inquire@paec.com',
            'address' => 'Manila, Philippines',
            'city' => 'Manila',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'published_at' => now(),
            'slug' => 'api-test-activity',
        ]);

        EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'general_admission',
            'name' => 'General Admission',
            'description' => 'General Admission',
            'price' => 599,
            'max_ticket' => 500,
            'visit_policy' => 'priority',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        return $event;
    }
}
