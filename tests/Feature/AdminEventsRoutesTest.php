<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Permission;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

/**
 * Covers admin /api/v1/events/* routes from routes/routes/events.php not fully exercised elsewhere.
 */
class AdminEventsRoutesTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private AdminUser $adminUser;

    private string $adminToken;

    private Category $category;

    private Event $event;

    private Schedule $schedule;

    private ScheduleTime $scheduleTime;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $permission = Permission::create([
            'name' => 'Events',
            'code' => 'events',
            'available_access' => ['view', 'create', 'update', 'delete', 'export'],
        ]);

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $adminRole->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'events-' . $access,
            ]);
        }

        RolePermission::create([
            'role_uuid' => $adminRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'events-export',
        ]);

        $this->grantAffiliateEventsAdminPermissions($adminRole);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $adminRole->uuid,
            'email' => 'admin-events-routes@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'Events',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser);

        $this->category = Category::create([
            'name' => 'Conference',
            'code' => 'conference-er',
            'type' => Category::TYPES['EVENT'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        EventSection::create([
            'name' => EventSection::FEATURED_SECTION,
            'title' => 'Featured',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => 'active',
        ]);

        $this->event = Event::create([
            'event_name' => 'Routes Coverage Event',
            'event_description' => 'Coverage',
            'contact_email' => 'routes@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-02',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);

        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '09:00:00',
            'time_end' => '18:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);
    }

    private function withAdminAuth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken];
    }

    #[Test]
    public function get_events_index_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function get_events_stats_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/stats')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function get_events_fun_stats_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/fun-stats')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function get_scanned_attendees_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $this->event->uuid . '/scanned-attendees')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);
    }

    #[Test]
    public function get_recent_purchased_tickets_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $this->event->uuid . '/recent-purchased-tickets')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function get_event_tickets_sold_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $this->event->uuid . '/event-tickets-sold')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function get_blocked_dates_for_event_returns_ok(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $this->event->uuid . '/blocked-dates')
            ->assertStatus(200);
    }

    #[Test]
    public function get_ticket_calendar_returns_counts_for_amusement_event(): void
    {
        if (! Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }

        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();

        $funEvent = Event::create([
            'event_name' => 'Fun Calendar Event',
            'event_description' => 'Amusement calendar',
            'contact_email' => 'fun-calendar@test.com',
            'category_uuid' => $this->category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $funEvent->uuid,
            'code' => 'FUN-CAL',
            'name' => 'Fun Pass',
            'price' => 100.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 50,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'first_name' => 'Cal',
            'last_name' => 'Guest',
            'email' => 'fun-calendar-guest@test.com',
            'password' => 'password123',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $funEvent->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-FUN-CAL-001',
            'total_amount' => 300.00,
            'sub_total' => 300.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ]);

        $baseTicket = [
            'user_uuid' => $customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $funEvent->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Cal Guest',
            'attendee_email' => 'fun-calendar-guest@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ];

        $setTicketTimestamps = function (Ticket $ticket, string $createdAt, ?string $usedAt = null): void {
            $ticket->created_at = $createdAt;
            $ticket->updated_at = $createdAt;
            if ($usedAt !== null) {
                $ticket->used_at = $usedAt;
            }
            $ticket->saveQuietly();
        };

        $setTicketTimestamps(Ticket::create(array_merge($baseTicket, [
            'ticket_number' => 'TN-PRI-1',
            'qr_code' => 'QR-PRI-1',
            'visit_policy' => 'priority',
            'valid_until' => '2026-07-01 23:59:59',
        ])), '2026-07-01 10:00:00');

        $setTicketTimestamps(Ticket::create(array_merge($baseTicket, [
            'ticket_number' => 'TN-PRI-2',
            'qr_code' => 'QR-PRI-2',
            'visit_policy' => 'priority',
            'valid_until' => '2026-07-01 23:59:59',
        ])), '2026-07-01 10:00:00');

        $setTicketTimestamps(Ticket::create(array_merge($baseTicket, [
            'ticket_number' => 'TN-PRI-3',
            'qr_code' => 'QR-PRI-3',
            'visit_policy' => 'priority',
            'valid_until' => '2026-07-15 23:59:59',
        ])), '2026-07-01 10:00:00');

        $setTicketTimestamps(Ticket::create(array_merge($baseTicket, [
            'ticket_number' => 'TN-FLX-1',
            'qr_code' => 'QR-FLX-1',
            'visit_policy' => 'flexible',
            'valid_until' => '2026-07-05 23:59:59',
        ])), '2026-07-01 10:00:00');

        $setTicketTimestamps(Ticket::create(array_merge($baseTicket, [
            'ticket_number' => 'TN-AUG-1',
            'qr_code' => 'QR-AUG-1',
            'visit_policy' => 'priority',
            'valid_until' => '2026-08-01 23:59:59',
        ])), '2026-07-10 08:00:00');

        $setTicketTimestamps(
            Ticket::create(array_merge($baseTicket, [
                'ticket_number' => 'TN-USED-1',
                'qr_code' => 'QR-USED-1',
                'visit_policy' => 'priority',
                'valid_until' => '2026-07-12 23:59:59',
                'status' => GeneralConstants::TICKET_STATUSES['USED'],
            ])),
            '2026-07-07 10:00:00',
            '2026-07-08 14:00:00'
        );

        Carbon::setTestNow('2026-07-10 12:00:00');
        $calendarAuthToken = auth('admin')->login($this->adminUser);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $calendarAuthToken])
            ->getJson('/api/v1/events/' . $funEvent->uuid . '/ticket-calendar?' . http_build_query([
                'year' => 2026,
                'month' => 7,
            ]));

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
            ])
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.month', 7)
            ->assertJsonPath('data.date_from', '2026-07-01')
            ->assertJsonPath('data.date_to', '2026-07-31')
            ->assertJsonPath('data.month_summary.flexible_ticket_count', 1)
            ->assertJsonPath('data.month_summary.new_sales_count', 1)
            ->assertJsonPath('data.month_summary.redeemed_count', 1)
            ->assertJsonPath('data.month_summary.total_ticket_count', 6);

        $days = collect($response->json('data.days'))->keyBy('date');
        $this->assertSame(2, $days['2026-07-01']['priority_ticket_count']);
        $this->assertSame(1, $days['2026-07-01']['flexible_ticket_count']);
        $this->assertSame(1, $days['2026-07-03']['flexible_ticket_count']);
        $this->assertSame(1, $days['2026-07-05']['flexible_ticket_count']);
        $this->assertSame(1, $days['2026-07-15']['priority_ticket_count']);
        $this->assertSame(1, $days['2026-07-08']['redeemed_count']);
        $this->assertFalse($days->has('2026-08-01'));

        Carbon::setTestNow();
    }

    #[Test]
    public function get_ticket_calendar_returns_403_for_non_amusement_event(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $this->event->uuid . '/ticket-calendar?' . http_build_query([
                'year' => 2026,
                'month' => 7,
            ]))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Ticket calendar is only available for amusement events.');
    }

    #[Test]
    public function get_ticket_calendar_validates_year_and_month(): void
    {
        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();

        $funEvent = Event::create([
            'event_name' => 'Fun Calendar Validation',
            'contact_email' => 'fun-val@test.com',
            'category_uuid' => $this->category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->getJson('/api/v1/events/' . $funEvent->uuid . '/ticket-calendar')
            ->assertStatus(422);
    }

    #[Test]
    public function submit_for_approval_transitions_event_to_pending(): void
    {
        $event = Event::create([
            'event_name' => 'Draft For Submit',
            'contact_email' => 'submit@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['DRAFT'],
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $event->uuid . '/submit-for-approval')
            ->assertStatus(200);

        $this->assertSame(
            GeneralConstants::EVENT_STATUSES['PENDING'],
            $event->fresh()->status
        );
    }

    #[Test]
    public function cancel_for_approval_returns_event_to_draft(): void
    {
        $event = Event::create([
            'event_name' => 'Pending For Cancel',
            'contact_email' => 'cancel-app@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PENDING'],
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $event->uuid . '/cancel-for-approval')
            ->assertStatus(200);

        $this->assertSame(
            GeneralConstants::EVENT_STATUSES['DRAFT'],
            $event->fresh()->status
        );
    }

    #[Test]
    public function approve_sets_event_to_approved(): void
    {
        $event = Event::create([
            'event_name' => 'Pending For Approve',
            'contact_email' => 'approve@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PENDING'],
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $event->uuid . '/approve')
            ->assertStatus(200);

        $this->assertSame(
            GeneralConstants::EVENT_STATUSES['APPROVED'],
            $event->fresh()->status
        );
        $this->assertNotNull($event->fresh()->approved_at);
    }

    #[Test]
    public function request_and_cancel_featured_flags(): void
    {
        $event = Event::create([
            'event_name' => 'Featured Flow',
            'contact_email' => 'feat@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $event->uuid . '/request-for-featured')
            ->assertStatus(200);
        $this->assertTrue($event->fresh()->is_request_for_featured);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $event->uuid . '/cancel-for-featured')
            ->assertStatus(200);
        $this->assertFalse($event->fresh()->is_request_for_featured);
    }

    #[Test]
    public function arrange_featured_events_updates_orders(): void
    {
        $e1 = Event::create([
            'event_name' => 'Arrange A',
            'contact_email' => 'a@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'is_featured' => true,
            'featured_order' => 2,
        ]);
        $e2 = Event::create([
            'event_name' => 'Arrange B',
            'contact_email' => 'b@test.com',
            'category_uuid' => $this->category->uuid,
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'is_featured' => true,
            'featured_order' => 1,
        ]);

        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/arrange-featured-events', [
                'events' => [
                    ['uuid' => $e1->uuid, 'featured_order' => 0],
                    ['uuid' => $e2->uuid, 'featured_order' => 1],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function patch_affiliate_settings_updates_event(): void
    {
        $this->withHeaders($this->withAdminAuth())
            ->patchJson('/api/v1/events/' . $this->event->uuid . '/affiliate-settings', [
                'affiliate_enabled' => true,
                'affiliate_commission_percent' => 8.5,
                'affiliate_ends_at' => null,
            ])
            ->assertStatus(200);

        $this->event->refresh();
        $this->assertTrue($this->event->affiliate_enabled);
        $this->assertEquals(8.5, (float) $this->event->affiliate_commission_percent);
    }

    #[Test]
    public function post_blocked_date_rejects_date_with_scheduled_tickets(): void
    {
        if (! Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function ($table) {
                $table->string('visit_policy')->nullable();
            });
        }

        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->firstOrFail();

        $funEvent = Event::create([
            'event_name' => 'Fun Block Validation',
            'contact_email' => 'fun-block@test.com',
            'category_uuid' => $this->category->uuid,
            'event_section_uuid' => $amusementSection->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $eventTicket = EventTicket::create([
            'event_uuid' => $funEvent->uuid,
            'code' => 'FUN-BLK',
            'name' => 'Fun Pass',
            'price' => 100.00,
            'is_bundle' => false,
            'display_order' => 1,
            'max_ticket' => 50,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'is_unlimited' => true,
        ]);

        $customerRole = Role::create([
            'name' => 'Customer Block',
            'code' => 'customer-block',
        ]);

        $customer = User::create([
            'role_uuid' => $customerRole->uuid,
            'first_name' => 'Block',
            'last_name' => 'Guest',
            'email' => 'fun-block-guest@test.com',
            'password' => 'password123',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $customer->uuid,
            'event_uuid' => $funEvent->uuid,
            'payment_method' => 'cash',
            'order_number' => 'ORD-FUN-BLK-001',
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $customer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $funEvent->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'attendee_name' => 'Block Guest',
            'attendee_email' => 'fun-block-guest@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'ticket_number' => 'TN-BLK-1',
            'qr_code' => 'QR-BLK-1',
            'visit_policy' => 'priority',
            'valid_until' => '2032-06-15 23:59:59',
        ]);
        $ticket->created_at = '2032-06-01 10:00:00';
        $ticket->updated_at = '2032-06-01 10:00:00';
        $ticket->saveQuietly();

        $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $funEvent->uuid . '/blocked-dates', [
                'blocked_date' => '2032-06-15',
                'reason' => 'Should fail',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'There are existing tickets scheduled for this date.');
    }

    #[Test]
    public function post_and_delete_blocked_date_round_trip(): void
    {
        $blockedDate = '2031-11-15';

        $create = $this->withHeaders($this->withAdminAuth())
            ->postJson('/api/v1/events/' . $this->event->uuid . '/blocked-dates', [
                'blocked_date' => $blockedDate,
                'reason' => 'Test block',
            ]);

        $create->assertStatus(201);
        $blockedUuid = $create->json('data.uuid');
        $this->assertNotEmpty($blockedUuid);

        $this->withHeaders($this->withAdminAuth())
            ->deleteJson('/api/v1/events/' . $this->event->uuid . '/blocked-dates/' . $blockedUuid)
            ->assertStatus(204);
    }

    #[Test]
    public function export_endpoints_return_csv(): void
    {
        $headers = $this->withAdminAuth();

        $this->withHeaders($headers)
            ->get('/api/v1/events/' . $this->event->uuid . '/export')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->withHeaders($headers)
            ->get('/api/v1/events/' . $this->event->uuid . '/export-attendee-report')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->withHeaders($headers)
            ->get('/api/v1/events/' . $this->event->uuid . '/export-used-tickets')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->withHeaders($headers)
            ->get('/api/v1/events/' . $this->event->uuid . '/export-tickets')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $this->withHeaders($headers)
            ->get('/api/v1/events/' . $this->event->uuid . '/export-occupied-seats?' . http_build_query([
                'schedule_uuid' => $this->schedule->uuid,
                'schedule_time_uuid' => $this->scheduleTime->uuid,
            ]))
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }
}
