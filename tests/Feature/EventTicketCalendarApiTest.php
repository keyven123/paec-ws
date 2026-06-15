<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventTicketCalendarApiTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $adminUser;

    private string $adminToken;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'Admin Calendar API',
            'code' => 'admin-calendar-api',
            'is_admin' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Events',
            'code' => 'events-cal',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'events-' . $access,
            ]);
        }

        $this->adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-calendar-api@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'Calendar',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser);

        $this->category = Category::create([
            'name' => 'Calendar API',
            'code' => 'calendar-api',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        EventSection::firstOrCreate(
            ['name' => EventSection::AMUSEMENT_SECTION],
            [
                'title' => 'Amusements',
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]
        );
    }

    private function withAdminAuth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken];
    }

    private function ensureVisitPolicyColumn(): void
    {
        if (! Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }
    }

    /**
     * @return array{event: Event, eventTicket: EventTicket, customer: User, transaction: Transaction}
     */
    private function createAmusementEventFixture(string $suffix = 'default'): array
    {
        $this->ensureVisitPolicyColumn();

        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();

        $event = Event::create([
            'event_name' => 'Calendar API Event ' . $suffix,
            'contact_email' => "calendar-api-{$suffix}@test.com",
            'category_uuid' => $this->category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'CAL-' . strtoupper($suffix),
            'name' => 'Pass',
            'price' => 100.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 50,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $customerRole = Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['CUSTOMER']['name']],
            ['name' => 'Customer']
        );

        $customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'first_name' => 'Cal',
            'last_name' => 'Guest',
            'email' => "calendar-guest-{$suffix}@test.com",
            'password' => 'password123',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $event->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-CAL-' . strtoupper($suffix),
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ]);

        return compact('event', 'eventTicket', 'customer', 'transaction');
    }

    private function createTicket(
        Event $event,
        EventTicket $eventTicket,
        User $customer,
        Transaction $transaction,
        array $attributes,
        ?string $createdAt = null,
        ?string $usedAt = null,
    ): Ticket {
        $ticket = Ticket::create(array_merge([
            'user_uuid' => $customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Guest',
            'attendee_email' => $customer->email,
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'ticket_number' => 'TN-' . uniqid(),
            'qr_code' => 'QR-' . uniqid(),
        ], $attributes));

        if ($createdAt !== null) {
            $ticket->created_at = $createdAt;
            $ticket->updated_at = $createdAt;
        }
        if ($usedAt !== null) {
            $ticket->used_at = $usedAt;
        }
        if ($createdAt !== null || $usedAt !== null) {
            $ticket->saveQuietly();
        }

        return $ticket;
    }

    private function getTicketCalendar(string $eventUuid, int $year, int $month)
    {
        return $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $eventUuid . '/ticket-calendar?' . http_build_query([
                'year' => $year,
                'month' => $month,
            ]));
    }

    #[Test]
    public function ticket_calendar_returns_expected_json_structure(): void
    {
        $fixture = $this->createAmusementEventFixture('structure');

        $response = $this->getTicketCalendar($fixture['event']->uuid, 2026, 8);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'year',
                    'month',
                    'date_from',
                    'date_to',
                    'month_summary' => [
                        'flexible_ticket_count',
                        'new_sales_count',
                        'redeemed_count',
                        'total_ticket_count',
                    ],
                    'days',
                ],
            ]);

        $firstDay = $response->json('data.days.0');
        if ($firstDay !== null) {
            $this->assertArrayHasKey('date', $firstDay);
            $this->assertArrayHasKey('priority_ticket_count', $firstDay);
            $this->assertArrayHasKey('flexible_ticket_count', $firstDay);
            $this->assertArrayHasKey('redeemed_count', $firstDay);
        }
    }

    #[Test]
    public function ticket_calendar_returns_zero_summary_for_empty_month(): void
    {
        $fixture = $this->createAmusementEventFixture('empty');

        $this->getTicketCalendar($fixture['event']->uuid, 2026, 1)
            ->assertStatus(200)
            ->assertJsonPath('data.month_summary.flexible_ticket_count', 0)
            ->assertJsonPath('data.month_summary.new_sales_count', 0)
            ->assertJsonPath('data.month_summary.redeemed_count', 0)
            ->assertJsonPath('data.month_summary.total_ticket_count', 0)
            ->assertJsonPath('data.days', []);
    }

    #[Test]
    public function ticket_calendar_excludes_unpaid_tickets(): void
    {
        $fixture = $this->createAmusementEventFixture('unpaid');

        $unpaidTransaction = Transaction::create([
            'user_uuid' => $fixture['customer']->uuid,
            'event_uuid' => $fixture['event']->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-CAL-UNPAID-' . uniqid(),
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'pending',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => 'pending',
        ]);

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $unpaidTransaction,
            [
                'visit_policy' => 'priority',
                'valid_until' => '2026-09-10 23:59:59',
            ],
            '2026-09-10 10:00:00',
        );

        $days = collect($this->getTicketCalendar($fixture['event']->uuid, 2026, 9)->json('data.days'))
            ->keyBy('date');

        $this->assertFalse($days->has('2026-09-10'));
    }

    #[Test]
    public function ticket_calendar_excludes_transferred_tickets(): void
    {
        $fixture = $this->createAmusementEventFixture('transferred');

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $fixture['transaction'],
            [
                'visit_policy' => 'priority',
                'valid_until' => '2026-09-12 23:59:59',
                'transferred_at' => '2026-09-01 12:00:00',
            ],
            '2026-09-01 10:00:00',
        );

        $days = collect($this->getTicketCalendar($fixture['event']->uuid, 2026, 9)->json('data.days'))
            ->keyBy('date');

        $this->assertFalse($days->has('2026-09-12'));
    }

    #[Test]
    public function ticket_calendar_includes_redeemed_only_day(): void
    {
        $fixture = $this->createAmusementEventFixture('redeemed-only');

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $fixture['transaction'],
            [
                'visit_policy' => 'priority',
                'valid_until' => '2026-10-25 23:59:59',
                'status' => GeneralConstants::TICKET_STATUSES['USED'],
            ],
            '2026-10-01 10:00:00',
            '2026-10-20 15:00:00',
        );

        $days = collect($this->getTicketCalendar($fixture['event']->uuid, 2026, 10)->json('data.days'))
            ->keyBy('date');

        $this->assertTrue($days->has('2026-10-20'));
        $this->assertSame(0, $days['2026-10-20']['priority_ticket_count']);
        $this->assertSame(0, $days['2026-10-20']['flexible_ticket_count']);
        $this->assertSame(1, $days['2026-10-20']['redeemed_count']);
    }

    #[Test]
    public function ticket_calendar_new_sales_counts_tickets_within_last_24_hours(): void
    {
        Carbon::setTestNow('2026-11-15 14:00:00');
        $this->adminToken = auth('admin')->login($this->adminUser);

        $fixture = $this->createAmusementEventFixture('new-sales');

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $fixture['transaction'],
            ['visit_policy' => 'priority', 'valid_until' => '2026-12-01 23:59:59'],
            '2026-11-15 10:00:00',
        );

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $fixture['transaction'],
            ['visit_policy' => 'priority', 'valid_until' => '2026-12-05 23:59:59'],
            '2026-11-10 10:00:00',
        );

        $this->getTicketCalendar($fixture['event']->uuid, 2026, 11)
            ->assertStatus(200)
            ->assertJsonPath('data.month_summary.new_sales_count', 1)
            ->assertJsonPath('data.month_summary.total_ticket_count', 2);

        Carbon::setTestNow();
    }

    #[Test]
    public function post_blocked_date_rejects_flexible_ticket_scheduled_on_date(): void
    {
        $fixture = $this->createAmusementEventFixture('flex-block');

        $this->createTicket(
            $fixture['event'],
            $fixture['eventTicket'],
            $fixture['customer'],
            $fixture['transaction'],
            [
                'visit_policy' => 'flexible',
                'valid_until' => '2033-03-20 23:59:59',
            ],
            '2033-03-01 10:00:00',
        );

        $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates', [
                'blocked_date' => '2033-03-10',
                'reason' => 'Maintenance',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'There are existing tickets scheduled for this date.');
    }

    #[Test]
    public function post_blocked_date_rejects_duplicate_date(): void
    {
        $fixture = $this->createAmusementEventFixture('dup-block');
        $blockedDate = '2033-04-20';

        $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates', [
                'blocked_date' => $blockedDate,
                'reason' => 'Holiday',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates', [
                'blocked_date' => $blockedDate,
                'reason' => 'Duplicate attempt',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This date is already blocked.');
    }

    #[Test]
    public function post_blocked_date_succeeds_when_no_tickets_scheduled(): void
    {
        $fixture = $this->createAmusementEventFixture('ok-block');
        $blockedDate = '2033-05-25';

        $create = $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates', [
                'blocked_date' => $blockedDate,
                'reason' => 'Private event',
            ]);

        $create->assertStatus(201)
            ->assertJsonPath('data.blocked_date', $blockedDate)
            ->assertJsonPath('data.reason', 'Private event')
            ->assertJsonPath('data.blockable_type', 'event')
            ->assertJsonPath('data.blockable_uuid', $fixture['event']->uuid);

        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates')
            ->assertStatus(200)
            ->assertJsonFragment([
                'blocked_date' => $blockedDate,
                'reason' => 'Private event',
            ]);
    }

    #[Test]
    public function delete_blocked_date_returns_not_found_for_unknown_uuid(): void
    {
        $fixture = $this->createAmusementEventFixture('delete-404');

        $this->withHeaders($this->withAdminAuth())
            ->deleteJson('/api/v1/events/' . $fixture['event']->uuid . '/blocked-dates/' . fake()->uuid())
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Blocked date not found');
    }
}
