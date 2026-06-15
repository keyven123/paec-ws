<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\EventTicket;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class EventTicketControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Event $testEvent;
    private Schedule $testSchedule;
    private ScheduleTime $testScheduleTime;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role
        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Event Tickets',
            'code' => 'event-tickets',
            'available_access' => ['view', 'create', 'edit', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'edit', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'event-tickets-' . $access,
            ]);
        }

        // Create admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Regular',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Create test event, schedule, and schedule time
        $this->testEvent = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test event description',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'contact_email' => 'contact@event.com',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->testSchedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $this->testScheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->testSchedule->uuid,
            'time_start' => '2025-01-01 10:00:00',
            'time_end' => '2025-01-01 12:00:00',
            'status' => 'published',
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListEventTickets()
    {
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'description' => 'General admission ticket',
            'price' => 50.00,
            'ticket_code' => 'GA001',
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 100,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/event-tickets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'event_uuid',
                        'schedule_time_uuid',
                        'code',
                        'name',
                        'price',
                        'ticket_code',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateAnEventTicket()
    {
        $eventTicketData = [
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'description' => 'General admission ticket',
            'price' => 50.00,
            'is_bundle' => false,
            'is_unlimited' => false,
            'display_order' => 1,
            'max_ticket' => 100,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets', $eventTicketData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'event_uuid',
                    'schedule_time_uuid',
                    'code',
                    'name',
                    'price',
                    'ticket_code',
                ],
            ]);

        $this->assertDatabaseHas('event_tickets', [
            'event_uuid' => $this->testEvent->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'price' => 50.00,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateABundleEventTicketWithCoupons()
    {
        $eventTicketData = [
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'BUNDLE001',
            'name' => 'Bundle Ticket',
            'description' => 'Bundle ticket with coupons',
            'price' => 50.00,
            'is_bundle' => true,
            'bundle_quantity' => 3,
            'is_unlimited' => false,
            'display_order' => 1,
            'max_ticket' => 100,
            'with_coupon' => true,
            'coupons' => [
                ['name' => 'ONCE', 'once_only' => true],
                ['name' => 'MULTI', 'once_only' => false],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets', $eventTicketData);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'BUNDLE001')
            ->assertJsonPath('data.is_bundle', true);

        $eventTicketUuid = $response->json('data.uuid');

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $eventTicketUuid,
            'event_uuid' => $this->testEvent->uuid,
            'code' => 'BUNDLE001',
            'is_bundle' => 1,
            'bundle_quantity' => 3,
        ]);

        $this->assertDatabaseHas('event_ticket_coupons', [
            'event_ticket_uuid' => $eventTicketUuid,
            'name' => 'ONCE',
            'once_only' => 1,
        ]);

        $this->assertDatabaseHas('event_ticket_coupons', [
            'event_ticket_uuid' => $eventTicketUuid,
            'name' => 'MULTI',
            'once_only' => 0,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUniqueCodePerEvent()
    {
        // Create first ticket
        EventTicket::create([
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'price' => 50.00,
            'ticket_code' => 'GA001',
        ]);

        // Try to create another ticket with same code
        $eventTicketData = [
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'code' => 'TICKET001', // Duplicate code
            'name' => 'VIP Admission',
            'price' => 100.00,
            'ticket_code' => 'VIP001',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets', $eventTicketData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'price' => 50.00,
            'ticket_code' => 'GA001',
        ]);

        // Test without token
        $response = $this->getJson('/api/v1/event-tickets');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/event-tickets', []);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDuplicateAnEventTicket(): void
    {
        $source = EventTicket::create([
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $this->testScheduleTime->uuid,
            'schedule_uuid' => $this->testSchedule->uuid,
            'code' => 'SOURCE001',
            'name' => 'VIP Pass',
            'description' => 'VIP description',
            'price' => 150.00,
            'is_bundle' => false,
            'is_unlimited' => false,
            'display_order' => 2,
            'max_ticket' => 50,
            'sold_ticket' => 5,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets/duplicate', [
            'uuid' => $source->uuid,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'VIP Pass duplicate')
            ->assertJsonPath('data.status', GeneralConstants::GENERAL_STATUSES['INACTIVE'])
            ->assertJsonPath('data.sold_ticket', 0)
            ->assertJsonPath('data.price', '150.00');

        $duplicateUuid = $response->json('data.uuid');
        $this->assertNotSame($source->uuid, $duplicateUuid);
        $this->assertNotSame('SOURCE001', $response->json('data.code'));

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $duplicateUuid,
            'event_uuid' => $this->testEvent->uuid,
            'name' => 'VIP Pass duplicate',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
            'sold_ticket' => 0,
        ]);

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $source->uuid,
            'name' => 'VIP Pass',
            'sold_ticket' => 5,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsValidationErrorWhenDuplicatingUnknownEventTicket(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets/duplicate', [
            'uuid' => fake()->uuid(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesDuplicateEventTicketRequest(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/event-tickets/duplicate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['uuid']);
    }
}
