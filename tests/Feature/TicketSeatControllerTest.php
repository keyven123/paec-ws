<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\Ticket;
use App\Models\Venue;
use App\Models\VenueSeat;
use App\Models\TicketSeat;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TicketSeatControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Venue $testVenue;
    private VenueSeat $testVenueSeat;
    private Ticket $testTicket;
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role
        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        // Create permissions
        $permission = Permission::create([
            'name' => 'Ticket Seats',
            'code' => 'ticket-seats',
            'available_access' => ['view', 'create', 'edit', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'edit', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'ticket-seats-' . $access,
            ]);
        }

        // Create admin user
        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        // Create test venue and venue seat
        $this->testVenue = Venue::create([
            'name' => 'Test Venue',
            'code' => 'TEST001',
            'type' => 'indoor',
            'address' => 'Test Address',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->testVenueSeat = VenueSeat::create([
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
            'status' => 'active',
        ]);

        // Create event, schedule, event ticket, transaction, and ticket
        $event = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test event description',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => ['conference', 'tech'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '2025-01-01 10:00:00',
            'time_end' => '2025-01-01 12:00:00',
            'status' => 'published',
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'schedule_uuid' => $schedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'price' => 50.00,
            'ticket_code' => 'GA001',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->adminUser->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'cash',
            'event_uuid' => $event->uuid,
            'order_number' => 'ORD-20250101-ABC123',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $this->testTicket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-ABC123DEF456',
            'status' => 'active',
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListTicketSeats()
    {
        $ticketSeat = TicketSeat::create([
            'ticket_uuid' => $this->testTicket->uuid,
            'venue_seat_uuid' => $this->testVenueSeat->uuid,
            'venue_uuid' => $this->testVenue->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
            'status' => 'active',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/ticket-seats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'ticket_uuid',
                        'venue_seat_uuid',
                        'col',
                        'row',
                        'seat_no',
                        'category',
                        'color',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateATicketSeat()
    {
        $ticketSeatData = [
            'ticket_uuid' => $this->testTicket->uuid,
            'venue_uuid' => $this->testVenue->uuid,
            'venue_seat_uuid' => $this->testVenueSeat->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
            'category' => 'bronze',
            'color' => 'bronze',
            'status' => 'active',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/ticket-seats', $ticketSeatData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'ticket_uuid',
                    'venue_seat_uuid',
                    'col',
                    'row',
                    'seat_no',
                    'category',
                    'color',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('ticket_seats', [
            'ticket_uuid' => $this->testTicket->uuid,
            'venue_seat_uuid' => $this->testVenueSeat->uuid,
            'col' => 'A',
            'row' => 1,
            'seat_no' => 1,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/ticket-seats');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/ticket-seats', []);
        $response->assertStatus(401);
    }
}
