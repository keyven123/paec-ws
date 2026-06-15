<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Permission;
use App\Models\RolePermission;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TicketControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    private AdminUser $adminUser;
    private Role $adminRole;
    private Event $testEvent;
    private EventTicket $testEventTicket;
    private Transaction $testTransaction;
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
            'name' => 'Tickets',
            'code' => 'tickets',
            'available_access' => ['view', 'create', 'edit', 'delete'],
        ]);

        // Assign permissions to admin role
        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $this->adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'tickets-' . $access,
            ]);
        }

        // Required by Route::post('tickets/add-to-user')->middleware('can:tickets-add')
        RolePermission::create([
            'role_uuid' => $this->adminRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'tickets-add',
        ]);

        // Create scanner permission (used by ticket scanner endpoints)
        $scannerPermission = Permission::create([
            'name' => 'Event Scanner',
            'code' => 'event-scanner',
            'available_access' => ['view', 'create'],
        ]);

        RolePermission::create([
            'role_uuid' => $this->adminRole->uuid,
            'permission_uuid' => $scannerPermission->uuid,
            'access' => 'event-scanner-create',
        ]);

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

        // Create test event
        $this->testEvent = Event::create([
            'event_name' => 'Test Event',
            'event_description' => 'Test event description',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => ['conference', 'tech'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        EventLocation::create([
            'event_uuid' => $this->testEvent->uuid,
            'city' => 'Manila',
            'address' => 'Test Venue, Manila',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // Create schedule and schedule time
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        // Create event ticket
        $this->testEventTicket = EventTicket::create([
            'event_uuid' => $this->testEvent->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'schedule_uuid' => $schedule->uuid,
            'code' => 'TICKET001',
            'name' => 'General Admission',
            'description' => 'General admission ticket',
            'price' => 50.00,
            'ticket_code' => 'GA001',
            'is_bundle' => false,
            'visit_policy' => 'flexible',
        ]);

        // Create transaction
        $this->testTransaction = Transaction::create([
            'user_uuid' => $this->adminUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-20250101-ABC123',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        // Generate JWT token for admin user
        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListTickets()
    {
        $ticket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $this->testTransaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-ABC123DEF456',
            'status' => 'active',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/tickets');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'user_uuid',
                        'transaction_uuid',
                        'event_uuid',
                        'event_ticket_uuid',
                        'attendee_name',
                        'attendee_email',
                        'qr_code',
                        'status',
                    ],
                ],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanCreateATicket()
    {
        $ticketData = [
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $this->testTransaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
            'status' => 'active',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets', $ticketData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'user_uuid',
                    'transaction_uuid',
                    'event_uuid',
                    'event_ticket_uuid',
                    'attendee_name',
                    'attendee_email',
                    'qr_code',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('tickets', [
            'user_uuid' => $this->adminUser->uuid,
            'attendee_name' => 'Jane Doe',
            'attendee_email' => 'jane@example.com',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanMarkTicketAsUsed()
    {
        $ticket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $this->testTransaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-ABC123DEF456',
            'status' => 'active',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/v1/tickets/{$ticket->uuid}/use");

        $response->assertStatus(200);

        $this->assertDatabaseHas('tickets', [
            'uuid' => $ticket->uuid,
            'status' => 'consumed',
        ]);

        $ticket->refresh();
        $this->assertNotNull($ticket->used_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForAllEndpoints()
    {
        // Test without token
        $response = $this->getJson('/api/v1/tickets');
        $response->assertStatus(401);

        $response = $this->postJson('/api/v1/tickets', []);
        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUsesProvidedTotalAmountForPaidNrWhenAddingTicketToUser(): void
    {
        // Allow quantity > 1 (seat_selection restricts adding multiple tickets)
        $this->testEvent->update([
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $this->adminRole->uuid,
        ]);

        $quantity = 2;
        $totalAmount = 246.9;
        $expectedUnitPrice = round($totalAmount / $quantity, 2);

        $payload = [
            'event_uuid' => $this->testEvent->uuid,
            'user_uuid' => $customer->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'type' => Ticket::TYPES['PAID_NR'],
            'quantity' => $quantity,
            'amount' => $totalAmount,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets/add-to-user', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
            ]);

        $transaction = Transaction::query()
            ->where('order_number', 'like', 'GIFT-%')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertEquals($totalAmount, (float) $transaction->total_amount);
        $this->assertEquals($totalAmount, (float) $transaction->sub_total);
        $this->assertSame('manual', $transaction->payment_provider);

        $this->assertDatabaseHas('transaction_orders', [
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'quantity' => $quantity,
            'price' => $expectedUnitPrice,
            'total_amount' => $totalAmount,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUsesProvidedTotalAmountForPaidToMerchantWhenAddingTicketToUser(): void
    {
        $this->testEvent->update([
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $this->adminRole->uuid,
        ]);

        $quantity = 3;
        $totalAmount = 100.0;
        $expectedUnitPrice = round($totalAmount / $quantity, 2);

        $payload = [
            'event_uuid' => $this->testEvent->uuid,
            'user_uuid' => $customer->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'type' => Ticket::TYPES['PAID_TO_MERCHANT'],
            'quantity' => $quantity,
            'amount' => $totalAmount,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets/add-to-user', $payload);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $transaction = Transaction::query()
            ->where('order_number', 'like', 'GIFT-%')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertEquals($totalAmount, (float) $transaction->total_amount);
        $this->assertEquals($totalAmount, (float) $transaction->sub_total);
        $this->assertSame('manual', $transaction->payment_provider);

        $this->assertDatabaseHas('transaction_orders', [
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'quantity' => $quantity,
            'price' => $expectedUnitPrice,
            'total_amount' => $totalAmount,
        ]);

        $this->assertEquals(
            $quantity,
            Ticket::query()
                ->where('transaction_uuid', $transaction->uuid)
                ->where('type', Ticket::TYPES['PAID_TO_MERCHANT'])
                ->count()
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSetsNullPaymentProviderForComplementaryWhenAddingTicketToUser(): void
    {
        $this->testEvent->update([
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $this->adminRole->uuid,
        ]);

        $payload = [
            'event_uuid' => $this->testEvent->uuid,
            'user_uuid' => $customer->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
            'quantity' => 1,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets/add-to-user', $payload);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $transaction = Transaction::query()
            ->where('order_number', 'like', 'GIFT-%')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertNull($transaction->payment_provider);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itStoresEmptyOtherInfoWhenAddingTicketToUser(): void
    {
        $this->testEvent->update([
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'other_info' => [
                'T-Shirt Size' => 'required',
                'Company' => [
                    'required' => 'optional',
                    'type' => 'text',
                ],
            ],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $this->adminRole->uuid,
        ]);

        $quantity = 2;
        $expectedOtherInfo = [
            'T-Shirt Size' => '',
            'Company' => '',
        ];

        $payload = [
            'event_uuid' => $this->testEvent->uuid,
            'user_uuid' => $customer->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
            'quantity' => $quantity,
            'other_info' => [
                $expectedOtherInfo,
                $expectedOtherInfo,
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets/add-to-user', $payload);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $tickets = Ticket::query()
            ->where('event_uuid', $this->testEvent->uuid)
            ->where('user_uuid', $customer->uuid)
            ->where('type', Ticket::TYPES['COMPLEMENTARY'])
            ->orderBy('created_at')
            ->get();

        $this->assertCount($quantity, $tickets);
        foreach ($tickets as $ticket) {
            $this->assertEquals($expectedOtherInfo, $ticket->other_info);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itBuildsEmptyOtherInfoFromEventWhenPayloadOmitsOtherInfo(): void
    {
        $this->testEvent->update([
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'other_info' => [
                'Dietary Restrictions' => 'optional',
            ],
        ]);

        $customer = User::factory()->create([
            'role_uuid' => $this->adminRole->uuid,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/v1/tickets/add-to-user', [
            'event_uuid' => $this->testEvent->uuid,
            'user_uuid' => $customer->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'type' => Ticket::TYPES['COMPLEMENTARY'],
            'quantity' => 1,
        ]);

        $response->assertStatus(201)->assertJson(['success' => true]);

        $ticket = Ticket::query()
            ->where('event_uuid', $this->testEvent->uuid)
            ->where('user_uuid', $customer->uuid)
            ->where('type', Ticket::TYPES['COMPLEMENTARY'])
            ->latest('created_at')
            ->first();

        $this->assertNotNull($ticket);
        $this->assertEquals(['Dietary Restrictions' => ''], $ticket->other_info);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itBlocksScannerWhenScheduleHasNotStartedYet(): void
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-04-17',
            'date_to' => '2025-04-17',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '20:00:00',
            'time_end' => '22:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->adminUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-20250417-TEST1',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-SCHEDULE-NOT-STARTED',
            'status' => 'active',
        ]);

        Carbon::setTestNow(Carbon::parse('2025-04-17 17:30:00', config('app.timezone', 'UTC')));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/tickets/qr-code/details?qr_code=' . $ticket->qr_code . '&event_uuid=' . $this->testEvent->uuid);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'schedule_not_started',
            ])
            ->assertJsonStructure([
                'success',
                'code',
                'message',
                'meta' => ['now', 'schedule_start', 'schedule_end', 'allowed_from', 'allowed_until'],
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itBlocksScannerWhenScheduleAlreadyEnded(): void
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-04-17',
            'date_to' => '2025-04-17',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '20:00:00',
            'time_end' => '22:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->adminUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-20250417-TEST2',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-SCHEDULE-ENDED',
            'status' => 'active',
        ]);

        Carbon::setTestNow(Carbon::parse('2025-04-17 22:30:00', config('app.timezone', 'UTC')));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/v1/tickets/{$ticket->uuid}/confirm-entry");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'code' => 'schedule_ended',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAllowsScannerWithinAllowedWindowAndConfirmsEntry(): void
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->testEvent->uuid,
            'date_from' => '2025-04-17',
            'date_to' => '2025-04-17',
            'status' => 'published',
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '20:00:00',
            'time_end' => '22:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->adminUser->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-20250417-TEST3',
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $this->adminUser->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->testEvent->uuid,
            'event_ticket_uuid' => $this->testEventTicket->uuid,
            'attendee_name' => 'John Doe',
            'attendee_email' => 'john@example.com',
            'qr_code' => 'QR-SCHEDULE-OK',
            'status' => 'active',
        ]);

        // 19:15 is within the allowed window (start 20:00; allowed from 19:00)
        Carbon::setTestNow(Carbon::parse('2025-04-17 19:15:00', config('app.timezone', 'UTC')));

        $detailsResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/tickets/qr-code/details?qr_code=' . $ticket->qr_code . '&event_uuid=' . $this->testEvent->uuid);

        $detailsResponse->assertStatus(200)
            ->assertJsonStructure(['data' => ['uuid', 'qr_code', 'schedule', 'schedule_time']]);

        $confirmResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson("/api/v1/tickets/{$ticket->uuid}/confirm-entry");

        $confirmResponse->assertStatus(200);

        $ticket->refresh();
        $this->assertNotNull($ticket->used_at);
    }
}
