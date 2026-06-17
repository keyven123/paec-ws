<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Transaction;
use Database\Seeders\AdminRolePermissionSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CustomerRolePermissionSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrganizerRolePermissionSeeder;
use Database\Seeders\PaecAdminUserSeeder;
use Database\Seeders\PaecOrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Database\Seeders\SuperadminRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EventLocationApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $adminToken = null;

    private ?string $customerToken = null;

    protected function setUp(): void
    {
        parent::setUp();

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
        ]);
    }

    public function test_admin_can_manage_event_locations(): void
    {
        ['event' => $event, 'taguig' => $taguig, 'manila' => $manila] = $this->createMultiLocationEvent();

        $this->authenticateAdmin();

        $this->withToken($this->adminToken)
            ->getJson("/api/v1/events/{$event->uuid}/locations")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['uuid' => $taguig->uuid, 'city' => 'Taguig'])
            ->assertJsonFragment(['uuid' => $manila->uuid, 'city' => 'Manila']);

        $create = $this->withToken($this->adminToken)
            ->postJson("/api/v1/events/{$event->uuid}/locations", [
                'name' => 'Quezon City Branch',
                'city' => 'Quezon City',
                'address' => 'Eastwood Mall, Quezon City',
            ]);

        $create->assertCreated()
            ->assertJsonPath('data.city', 'Quezon City')
            ->assertJsonPath('data.label', 'Quezon City Branch');

        $newLocationUuid = $create->json('data.uuid');

        $this->withToken($this->adminToken)
            ->putJson("/api/v1/events/{$event->uuid}/locations/{$newLocationUuid}", [
                'name' => 'Eastwood Branch',
                'city' => 'Quezon City',
                'address' => 'Eastwood Mall, Quezon City',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Eastwood Branch');

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/events/{$event->uuid}/locations/{$newLocationUuid}")
            ->assertOk();

        $this->assertDatabaseMissing('event_locations', [
            'uuid' => $newLocationUuid,
        ]);
    }

    public function test_public_event_detail_includes_active_locations(): void
    {
        ['event' => $event, 'taguig' => $taguig, 'manila' => $manila] = $this->createMultiLocationEvent();

        $this->getJson("/api/v1/public/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $event->uuid)
            ->assertJsonCount(2, 'data.event_locations')
            ->assertJsonFragment(['uuid' => $taguig->uuid, 'city' => 'Taguig'])
            ->assertJsonFragment(['uuid' => $manila->uuid, 'city' => 'Manila']);
    }

    public function test_event_tickets_sold_returns_location_sales(): void
    {
        ['event' => $event, 'taguig' => $taguig, 'manila' => $manila] = $this->createMultiLocationEvent();

        $this->authenticateAdmin();

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/v1/events/{$event->uuid}/event-tickets-sold");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'location_sales' => [
                    ['uuid', 'city', 'label', 'total_orders', 'total_amount', 'ticket_sold'],
                ],
                'total_orders',
                'total_amount',
                'ticket_sold',
            ]);

        $locationSales = collect($response->json('location_sales'));
        $this->assertTrue($locationSales->contains('uuid', $taguig->uuid));
        $this->assertTrue($locationSales->contains('uuid', $manila->uuid));
    }

    public function test_temp_transaction_requires_location_when_multiple_exist(): void
    {
        ['event' => $event, 'ticket' => $ticket] = $this->createMultiLocationEvent();

        $this->authenticateCustomer();

        $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions', [
                'event_uuid' => $event->uuid,
                'tickets' => [
                    [
                        'event_ticket_uuid' => $ticket->uuid,
                        'quantity' => 1,
                        'valid_until' => now()->addDay()->toDateString(),
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_location_uuid']);
    }

    public function test_temp_transaction_rejects_invalid_location(): void
    {
        ['event' => $event, 'ticket' => $ticket] = $this->createMultiLocationEvent();
        $otherEvent = $this->createSingleLocationEvent()['event'];

        $this->authenticateCustomer();

        $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions', [
                'event_uuid' => $event->uuid,
                'event_location_uuid' => $otherEvent->eventLocations()->first()->uuid,
                'tickets' => [
                    [
                        'event_ticket_uuid' => $ticket->uuid,
                        'quantity' => 1,
                        'valid_until' => now()->addDay()->toDateString(),
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_location_uuid']);
    }

    public function test_temp_transaction_auto_selects_single_location(): void
    {
        ['event' => $event, 'ticket' => $ticket, 'location' => $location] = $this->createSingleLocationEvent();

        $this->authenticateCustomer();

        $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions', [
                'event_uuid' => $event->uuid,
                'tickets' => [
                    [
                        'event_ticket_uuid' => $ticket->uuid,
                        'quantity' => 1,
                        'valid_until' => now()->addDay()->toDateString(),
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('temp_transactions', [
            'event_uuid' => $event->uuid,
            'event_location_uuid' => $location->uuid,
        ]);
    }

    public function test_temp_transaction_uses_location_organization_account(): void
    {
        ['event' => $event, 'ticket' => $ticket, 'manila' => $manila] = $this->createMultiLocationEvent();
        $branchOrg = Organization::factory()->create(['name' => 'PAEC Manila Branch']);

        $manila->update(['organization_uuid' => $branchOrg->uuid]);

        $this->authenticateCustomer();

        $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions', [
                'event_uuid' => $event->uuid,
                'event_location_uuid' => $manila->uuid,
                'tickets' => [
                    [
                        'event_ticket_uuid' => $ticket->uuid,
                        'quantity' => 1,
                        'valid_until' => now()->addDay()->toDateString(),
                    ],
                ],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('temp_transactions', [
            'event_uuid' => $event->uuid,
            'event_location_uuid' => $manila->uuid,
            'organization_uuid' => $branchOrg->uuid,
        ]);
    }

    public function test_checkout_persists_event_location_on_transaction_and_tickets(): void
    {
        Mail::fake();
        config(['app.debug' => true]);

        ['event' => $event, 'ticket' => $ticket, 'manila' => $manila] = $this->createMultiLocationEvent();
        $branchOrg = Organization::factory()->create(['name' => 'PAEC Manila Branch']);
        $manila->update(['organization_uuid' => $branchOrg->uuid]);

        $this->authenticateCustomer();

        $tempResponse = $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions', [
                'event_uuid' => $event->uuid,
                'event_location_uuid' => $manila->uuid,
                'tickets' => [
                    [
                        'event_ticket_uuid' => $ticket->uuid,
                        'quantity' => 1,
                        'valid_until' => now()->addDay()->toDateString(),
                    ],
                ],
            ])
            ->assertCreated();

        $tempUuid = $tempResponse->json('uuid');

        $this->withToken($this->customerToken)
            ->postJson('/api/v1/customer/temp-transactions/checkout-bypass', [
                'temp_transaction_uuid' => $tempUuid,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('transactions', [
            'event_uuid' => $event->uuid,
            'event_location_uuid' => $manila->uuid,
            'organization_uuid' => $branchOrg->uuid,
        ]);

        $transaction = Transaction::query()
            ->where('event_uuid', $event->uuid)
            ->where('event_location_uuid', $manila->uuid)
            ->first();

        $this->assertNotNull($transaction);

        $this->assertDatabaseHas('tickets', [
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_location_uuid' => $manila->uuid,
            'organization_uuid' => $branchOrg->uuid,
        ]);

        $this->authenticateAdmin();

        $salesResponse = $this->withToken($this->adminToken)
            ->getJson("/api/v1/events/{$event->uuid}/event-tickets-sold")
            ->assertOk();

        $manilaSales = collect($salesResponse->json('location_sales'))
            ->firstWhere('uuid', $manila->uuid);

        $this->assertSame(1, $manilaSales['total_orders']);
        $this->assertSame(1, $manilaSales['ticket_sold']);
        $this->assertGreaterThan(0, (float) $manilaSales['total_amount']);

        $taguig = $event->eventLocations()->where('city', 'Taguig')->first();
        $taguigSales = collect($salesResponse->json('location_sales'))
            ->firstWhere('uuid', $taguig->uuid);

        $this->assertSame(0, $taguigSales['total_orders']);
        $this->assertSame(0, $taguigSales['ticket_sold']);
    }

    public function test_admin_cannot_remove_last_active_location(): void
    {
        ['event' => $event, 'taguig' => $taguig, 'manila' => $manila] = $this->createMultiLocationEvent();

        $this->authenticateAdmin();

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/events/{$event->uuid}/locations/{$manila->uuid}")
            ->assertOk();

        $this->withToken($this->adminToken)
            ->deleteJson("/api/v1/events/{$event->uuid}/locations/{$taguig->uuid}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one active location is required for this activity.');
    }

    /**
     * @return array{
     *     event: Event,
     *     ticket: EventTicket,
     *     taguig: EventLocation,
     *     manila: EventLocation
     * }
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
     * @return array{event: Event, ticket: EventTicket, location: EventLocation}
     */
    private function createSingleLocationEvent(): array
    {
        ['event' => $event, 'ticket' => $ticket] = $this->createPublishedEvent();

        $organization = Organization::where('email', 'inquire@paec.com')->firstOrFail();

        $location = EventLocation::create([
            'event_uuid' => $event->uuid,
            'city' => 'Taguig',
            'address' => 'Bonifacio Global City, Taguig',
            'organization_uuid' => $organization->uuid,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return compact('event', 'ticket', 'location');
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

    private function authenticateAdmin(): void
    {
        if ($this->adminToken) {
            return;
        }

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@paec.com',
            'password' => 'P@ec2026!!',
        ]);

        $response->assertOk();
        $this->adminToken = $response->json('access_token');
    }

    private function authenticateCustomer(): void
    {
        if ($this->customerToken) {
            return;
        }

        $response = $this->postJson('/api/v1/login', [
            'email' => 'customer@paec.com',
            'password' => 'P@ec2026!!',
        ]);

        $response->assertOk();
        $this->customerToken = $response->json('access_token');
    }
}
