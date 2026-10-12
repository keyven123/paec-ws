<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use Database\Seeders\AdminRolePermissionSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerRolePermissionSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\EventSectionSeeder;
use Database\Seeders\PaecOrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Database\Seeders\SuperadminRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicAmusementsCatalogTest extends TestCase
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
            EventSectionSeeder::class,
        ]);
    }

    #[Test]
    public function public_amusements_catalog_includes_featured_section_events(): void
    {
        $category = Category::query()->firstOrFail();
        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();
        $featuredSection = EventSection::where('name', EventSection::FEATURED_SECTION)->firstOrFail();

        $catalogEvent = Event::create([
            'event_name' => 'Catalog Only Activity',
            'contact_email' => 'catalog@test.com',
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'city' => 'Taguig',
        ]);

        $featuredEvent = Event::create([
            'event_name' => 'Featured Marketplace Activity',
            'contact_email' => 'featured@test.com',
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $featuredSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'is_featured' => true,
            'featured_order' => 0,
            'city' => 'Manila',
        ]);

        $response = $this->getJson('/api/v1/public/events?type=amusements&per_page=100')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('event_name')->all();

        $this->assertContains($catalogEvent->event_name, $names);
        $this->assertContains($featuredEvent->event_name, $names);
    }

    #[Test]
    public function promo_catalog_lists_only_activities_with_discounted_tickets(): void
    {
        $category = Category::query()->firstOrFail();
        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();

        $makeEvent = fn (string $name) => Event::create([
            'event_name' => $name,
            'contact_email' => 'promo@test.com',
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'city' => 'Taguig',
        ]);
        $makeTicket = fn (Event $event, string $code, float $price, ?string $type = null, ?float $value = null) => EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => $code,
            'name' => $code,
            'price' => $price,
            'discount_type' => $type,
            'discount_value' => $value,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $discounted = $makeEvent('Discounted Activity');
        $makeTicket($discounted, 'REG', 500);
        $makeTicket($discounted, 'KIDS', 400, GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'], 25);
        $makeTicket($discounted, 'VIP', 1000, GeneralConstants::DISCOUNT_TYPES['AMOUNT'], 100);

        $amountOnly = $makeEvent('Amount Discount Activity');
        $makeTicket($amountOnly, 'REG', 800, GeneralConstants::DISCOUNT_TYPES['AMOUNT'], 150);
        $makeTicket($amountOnly, 'KIDS', 300, GeneralConstants::DISCOUNT_TYPES['AMOUNT'], 50);

        $fullPrice = $makeEvent('Full Price Activity');
        $makeTicket($fullPrice, 'REG', 500);

        $response = $this->getJson('/api/v1/public/events?type=amusements&fun_type=promo&per_page=100')
            ->assertOk();

        $events = collect($response->json('data'))->keyBy('event_name');

        $this->assertTrue($events->has('Discounted Activity'));
        $this->assertFalse($events->has('Full Price Activity'));
        $this->assertEquals(400, $events['Discounted Activity']['price_start']);
        $this->assertEquals(300, $events['Discounted Activity']['discounted_price_start']);
        $this->assertSame(25, $events['Discounted Activity']['max_discount_percent']);
        $this->assertSame('percentage', $events['Discounted Activity']['discount_type']);
        $this->assertEquals(100, $events['Discounted Activity']['max_discount_amount']);

        $this->assertSame('amount', $events['Amount Discount Activity']['discount_type']);
        $this->assertEquals(150, $events['Amount Discount Activity']['max_discount_amount']);
        $this->assertEquals(250, $events['Amount Discount Activity']['discounted_price_start']);

        $catalog = collect($this->getJson('/api/v1/public/events?type=amusements&per_page=100')->json('data'))
            ->keyBy('event_name');
        $this->assertNull($catalog['Full Price Activity']['discounted_price_start']);
        $this->assertNull($catalog['Full Price Activity']['max_discount_percent']);
        $this->assertNull($catalog['Full Price Activity']['discount_type']);
    }

    #[Test]
    public function admin_amusements_catalog_includes_featured_section_events(): void
    {
        $category = Category::query()->firstOrFail();
        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();
        $featuredSection = EventSection::where('name', EventSection::FEATURED_SECTION)->firstOrFail();
        $admin = AdminUser::where('email', 'admin@paec.com')->firstOrFail();
        $token = auth('admin')->login($admin);

        $featuredEvent = Event::create([
            'event_name' => 'Admin Featured Activity',
            'contact_email' => 'admin-featured@test.com',
            'category_uuid' => $category->uuid,
            'event_section_uuid' => $featuredSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'is_featured' => true,
            'featured_order' => 0,
            'city' => 'Taguig',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/events?event_section_type=amusements&per_page=100')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('event_name')->all();

        $this->assertContains($featuredEvent->event_name, $names);

        $statsResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/events/fun-stats')
            ->assertOk();

        $this->assertGreaterThanOrEqual(
            1,
            (int) $statsResponse->json('data.total_published'),
        );
    }
}
