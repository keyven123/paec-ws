<?php

namespace Tests\Feature\Coverage\HttpAnalyticsCms;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAdminWithAnalyticsPermissions;
use Tests\Feature\Coverage\AnalyticsRepository\AnalyticsRepositoryTestCase;

/**
 * Admin analytics endpoints. "Now" is frozen at Wed 2026-05-20 15:30 (Asia/Manila) by the base class,
 * which also teaches SQLite the MySQL DATE_FORMAT used by the sales trend.
 */
class AnalyticsControllerTest extends AnalyticsRepositoryTestCase
{
    use CreatesAdminWithAnalyticsPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAnalyticsAdmin();
    }

    /**
     * Org Event (org-owned): 1000 today + 500 in March; Admin Event (no org, created by the analytics admin): 300 on
     * Tuesday this week; Orphan Event (no org, no creator): 200 on May 1; Deleted Event: 100 in April then soft-deleted;
     * an old paid 50 outside the six-month trend window; a failed 999 today that every KPI must ignore.
     *
     * @return array{org: Event, admin: Event, orphan: Event, deleted: Event}
     */
    private function seedSalesFixtures(): array
    {
        $org = $this->createEvent($this->organization, 'Org Event');
        $admin = $this->createEvent($this->organization, 'Admin Event', ['organization_uuid' => null]);
        $this->setEventCreator($admin, $this->analyticsAdmin->uuid);
        $orphan = $this->createEvent($this->organization, 'Orphan Event', ['organization_uuid' => null]);
        $this->setEventCreator($orphan, null);
        $deleted = $this->createEvent($this->organization, 'Deleted Event');

        $this->createTransaction($org, 1000, '2026-05-20 10:00:00');
        $this->createTransaction($org, 500, '2026-03-10 10:00:00');
        $this->createTransaction($admin, 300, '2026-05-19 09:00:00');
        $this->createTransaction($orphan, 200, '2026-05-01 09:00:00');
        $this->createTransaction($deleted, 100, '2026-04-15 09:00:00');
        $this->createTransaction($org, 50, '2025-01-01 09:00:00');
        $this->createTransaction($org, 999, '2026-05-20 11:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'paid_at' => null,
        ]);

        $deleted->delete();

        return compact('org', 'admin', 'orphan', 'deleted');
    }

    /**
     * Event's model hooks stamp created_by with the logged-in admin, so the creator is set directly.
     */
    private function setEventCreator(Event $event, ?string $adminUuid): void
    {
        DB::table('events')->where('uuid', $event->uuid)->update(['created_by' => $adminUuid]);
    }

    private function createTicket(Event $event, string $name): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => Str::slug($name, '_'),
            'name' => $name,
            'description' => $name,
            'price' => 250,
            'max_ticket' => 100,
            'visit_policy' => 'priority',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    private function createOrder(Transaction $transaction, string $eventTicketUuid, int $quantity, float $price): TransactionOrder
    {
        return TransactionOrder::create([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicketUuid,
            'quantity' => $quantity,
            'price' => $price,
            'total_amount' => $quantity * $price,
        ]);
    }

    #[Test]
    public function salesReturnsKpisAndMonthlyTrendForPaidTransactions(): void
    {
        $this->seedSalesFixtures();

        $response = $this->analyticsGet('/api/v1/analytics/sales')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Sales analytics data');

        $this->assertEquals(2150, $response->json('data.total_sales'));
        $this->assertEquals(1500, $response->json('data.this_month_sales'));
        $this->assertEquals(1300, $response->json('data.this_week_sales'));
        $this->assertEquals(1000, $response->json('data.today_sales'));

        $this->assertSame([
            ['month' => 'March 2026', 'year_month' => '2026-03', 'total' => 500],
            ['month' => 'April 2026', 'year_month' => '2026-04', 'total' => 100],
            ['month' => 'May 2026', 'year_month' => '2026-05', 'total' => 1500],
        ], $response->json('data.monthly_trend'));
    }

    #[Test]
    public function salesTopSellingEventsResolveOrganizerFromOrganizationOrCreator(): void
    {
        $events = $this->seedSalesFixtures();

        $top = collect($this->analyticsGet('/api/v1/analytics/sales')->assertOk()->json('data.top_selling_events'))
            ->keyBy('event_uuid');

        $this->assertSame(
            [$events['org']->uuid, $events['admin']->uuid, $events['orphan']->uuid, $events['deleted']->uuid],
            $top->keys()->all(),
        );

        $this->assertSame('Org Event', $top[$events['org']->uuid]['event_name']);
        $this->assertSame('Analytics Org', $top[$events['org']->uuid]['organizer']);
        $this->assertEquals(1550, $top[$events['org']->uuid]['total_sales']);
        $this->assertEquals(3, $top[$events['org']->uuid]['order_count']);

        $this->assertSame('Admin Event', $top[$events['admin']->uuid]['event_name']);
        $this->assertSame('Analytics Admin', $top[$events['admin']->uuid]['organizer']);

        $this->assertSame('Orphan Event', $top[$events['orphan']->uuid]['event_name']);
        $this->assertSame('Unknown', $top[$events['orphan']->uuid]['organizer']);

        $this->assertSame('Unknown Event', $top[$events['deleted']->uuid]['event_name']);
        $this->assertSame('Unknown', $top[$events['deleted']->uuid]['organizer']);
        $this->assertEquals(100, $top[$events['deleted']->uuid]['total_sales']);
    }

    #[Test]
    public function salesByTicketTypeAggregatesPaidOrdersOnly(): void
    {
        $event = $this->createEvent($this->organization, 'Ticketed Event');
        $vip = $this->createTicket($event, 'VIP Pass');

        $paid = $this->createTransaction($event, 750, '2026-05-19 10:00:00');
        $failed = $this->createTransaction($event, 250, '2026-05-19 11:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'paid_at' => null,
        ]);

        $this->createOrder($paid, $vip->uuid, 2, 250);
        $this->createOrder($paid, $vip->uuid, 1, 250);
        $this->createOrder($failed, $vip->uuid, 1, 250);

        $missingTicketUuid = (string) Str::uuid();
        $this->createOrder($paid, $missingTicketUuid, 1, 40);

        $byTicket = collect($this->analyticsGet('/api/v1/analytics/sales')->assertOk()->json('data.sales_by_ticket_type'))
            ->keyBy('event_ticket_uuid');

        $this->assertSame([$vip->uuid, $missingTicketUuid], $byTicket->keys()->all());

        $this->assertSame('VIP Pass', $byTicket[$vip->uuid]['ticket_name']);
        $this->assertSame('Ticketed Event', $byTicket[$vip->uuid]['event_name']);
        $this->assertEquals(750, $byTicket[$vip->uuid]['total_sales']);
        $this->assertEquals(3, $byTicket[$vip->uuid]['total_quantity']);

        $this->assertSame('Unknown Ticket', $byTicket[$missingTicketUuid]['ticket_name']);
        $this->assertSame('Unknown Event', $byTicket[$missingTicketUuid]['event_name']);
        $this->assertEquals(40, $byTicket[$missingTicketUuid]['total_sales']);
    }

    #[Test]
    public function salesReturnsZeroedKpisWithoutPaidTransactions(): void
    {
        $this->analyticsGet('/api/v1/analytics/sales')
            ->assertOk()
            ->assertJsonPath('data.monthly_trend', [])
            ->assertJsonPath('data.top_selling_events', [])
            ->assertJsonPath('data.sales_by_ticket_type', []);
    }

    #[Test]
    public function eventsResolveOrganizerFromCreatorWhenEventHasNoOrganization(): void
    {
        $events = $this->seedSalesFixtures();

        $response = $this->analyticsGet('/api/v1/analytics/events')->assertOk();

        $top = collect($response->json('data.top_performing_events'))->keyBy('event_uuid');
        $this->assertSame('Analytics Org', $top[$events['org']->uuid]['organizer']);
        $this->assertSame('Analytics Admin', $top[$events['admin']->uuid]['organizer']);
        $this->assertSame('Unknown', $top[$events['orphan']->uuid]['organizer']);
        $this->assertEquals(300, $top[$events['admin']->uuid]['total_revenue']);

        $all = collect($response->json('data.all_events'))->keyBy('event_uuid');
        $this->assertSame('Analytics Admin', $all[$events['admin']->uuid]['organizer']);
        $this->assertSame('Unknown', $all[$events['orphan']->uuid]['organizer']);
        $this->assertEquals(200, $all[$events['orphan']->uuid]['total_revenue']);
        $this->assertSame(1, $all[$events['orphan']->uuid]['order_count']);
        $this->assertArrayNotHasKey($events['deleted']->uuid, $all->all());
    }

    #[Test]
    public function eventsFallBackToCreatorFirstNameAccessorChain(): void
    {
        $creator = AdminUser::create([
            'role_uuid' => $this->analyticsAdmin->role_uuid,
            'email' => 'solo-creator@test.com',
            'password' => 'password123',
            'first_name' => 'Solo',
            'last_name' => 'Creator',
            'middle_name' => 'M',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $event = $this->createEvent($this->organization, 'Creator Event', ['organization_uuid' => null]);
        $this->setEventCreator($event, $creator->uuid);

        $all = collect($this->analyticsGet('/api/v1/analytics/events')->assertOk()->json('data.all_events'))
            ->keyBy('event_uuid');

        $this->assertSame('Solo M Creator', $all[$event->uuid]['organizer']);
    }

    #[Test]
    public function salesReportDownloadsCsvOfPaidEventTransactionsInRange(): void
    {
        $event = $this->createEvent($this->organization, 'Report Event');
        $ticket = $this->createTicket($event, 'Report Pass');
        $inRange = $this->createTransaction($event, 450, '2026-05-10 10:00:00');
        $outOfRange = $this->createTransaction($event, 999, '2026-04-01 10:00:00');
        $this->createOrder($inRange, $ticket->uuid, 1, 450);
        $this->createOrder($outOfRange, $ticket->uuid, 1, 999);

        $response = $this->analyticsGet('/api/v1/analytics/sales-report', [
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-20',
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="Sales_Report_2026-05-01_to_2026-05-20.csv"')
            ->assertHeader('Access-Control-Expose-Headers', 'Content-Disposition, Content-Type')
            ->assertHeader('Pragma', 'no-cache');

        $csv = $response->getContent();
        $this->assertStringStartsWith('"Order Number"', ltrim($csv, "\xEF\xBB\xBF"));
        $this->assertStringContainsString($inRange->order_number, $csv);
        $this->assertStringNotContainsString($outOfRange->order_number, $csv);
    }

    #[Test]
    public function salesReportValidatesDateRange(): void
    {
        $this->analyticsGet('/api/v1/analytics/sales-report', [
            'start_date' => '2026-05-20',
            'end_date' => '2026-05-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function cancelledCheckoutsDownloadsCsvOfCancelledOrdersInRange(): void
    {
        $event = $this->createEvent($this->organization, 'Cancelled Event');
        $ticket = $this->createTicket($event, 'Day Pass');
        $cancelled = $this->createTransaction($event, 600, '2026-05-15 10:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'paid_at' => null,
        ]);
        $this->createOrder($cancelled, $ticket->uuid, 2, 300);
        $this->createTransaction($event, 700, '2026-05-15 11:00:00');

        $response = $this->analyticsGet('/api/v1/analytics/cancelled-checkouts', [
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-15',
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="Cancelled_Checkouts_Report_2026-05-15_to_2026-05-15.csv"',
            )
            ->assertHeader('Cache-Control', 'no-cache, private');

        $csv = $response->getContent();
        $this->assertStringContainsString('Event Name', $csv);
        $this->assertStringContainsString('Cancelled Event', $csv);
        $this->assertStringContainsString('Day Pass (qty:2)', $csv);
        $this->assertStringNotContainsString('700', $csv);
    }

    #[Test]
    public function cancelledCheckoutsRequiresDates(): void
    {
        $this->analyticsGet('/api/v1/analytics/cancelled-checkouts')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    #[Test]
    public function revenuePerEventSeriesReturnsStackedSeriesForOrganization(): void
    {
        $event = $this->createEvent($this->organization, 'Series Event');
        $other = $this->createEvent($this->otherOrganization, 'Other Org Event');
        $this->createTransaction($event, 120, '2026-05-19 10:00:00');
        $this->createTransaction($other, 999, '2026-05-19 10:00:00');

        $this->analyticsGet('/api/v1/analytics/revenue-per-event-series', [
            'granularity' => 'daily',
            'organization_uuid' => $this->organization->uuid,
            'start_date' => '2026-05-18',
            'end_date' => '2026-05-19',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Revenue per event series')
            ->assertJsonPath('data.granularity', 'daily')
            ->assertJsonPath('data.start_date', '2026-05-18')
            ->assertJsonPath('data.end_date', '2026-05-19')
            ->assertJsonPath('data.events', [['event_uuid' => $event->uuid, 'event_name' => 'Series Event']])
            ->assertJsonPath('data.series', [
                ['date' => '2026-05-18', 'amounts' => [0]],
                ['date' => '2026-05-19', 'amounts' => [120]],
            ]);
    }

    #[Test]
    public function revenuePerEventSeriesUsesDefaultRangeWithoutDates(): void
    {
        $event = $this->createEvent($this->organization, 'Monthly Event');
        $this->createTransaction($event, 80, '2026-05-02 10:00:00');

        $response = $this->analyticsGet('/api/v1/analytics/revenue-per-event-series', ['granularity' => 'monthly'])
            ->assertOk()
            ->assertJsonPath('data.granularity', 'monthly')
            ->assertJsonPath('data.events.0.event_uuid', $event->uuid);

        $this->assertSame('2026-05-20', $response->json('data.end_date'));
        $this->assertSame([80], last($response->json('data.series'))['amounts']);
    }

    #[Test]
    public function revenuePerEventSeriesRejectsUnknownGranularity(): void
    {
        $this->analyticsGet('/api/v1/analytics/revenue-per-event-series', ['granularity' => 'decade'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['granularity']);
    }
}
