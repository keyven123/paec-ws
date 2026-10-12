<?php

namespace Tests\Feature\Coverage\GapOrgAnalytics;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\TicketPurchasePricingService;
use ErrorException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\AnalyticsRepository\AnalyticsRepositoryTestCase;

/**
 * Organizer-scoped analytics endpoints. "Now" is frozen at Wed 2026-05-20 15:30 (Asia/Manila) by the base class.
 */
class OrganizerAnalyticsControllerGapTest extends AnalyticsRepositoryTestCase
{
    private string $token;

    private AdminUser $analyticsAdmin;

    private function loginAnalyticsAdmin(?Organization $organization, bool $platformAdmin = false): AdminUser
    {
        $role = Role::create([
            'organization_uuid' => $organization?->uuid,
            'name' => 'Analytics Viewer '.Str::random(4),
            'code' => $platformAdmin ? 'analytics-admin-'.Str::random(4) : GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => $platformAdmin,
        ]);

        $permission = Permission::firstOrCreate(['code' => 'organizer-analytics'], [
            'name' => 'Organizer Analytics',
            'available_access' => ['view'],
            'role_scope' => 'shared',
        ]);
        RolePermission::create([
            'role_uuid' => $role->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'organizer-analytics-view',
        ]);

        $this->analyticsAdmin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organization?->uuid,
            'email' => 'org-analytics-'.Str::random(8).'@test.com',
            'password' => 'password123',
            'first_name' => 'Ana',
            'last_name' => 'Lytics',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->token = auth('admin')->login($this->analyticsAdmin);

        return $this->analyticsAdmin;
    }

    private function organizerGet(string $uri)
    {
        return $this->getJson('/api/v1/analytics/organizer'.$uri, ['Authorization' => 'Bearer '.$this->token]);
    }

    private function merchantSales(Transaction $transaction): float
    {
        $transaction = $transaction->fresh();
        $rate = app(OrganizerAccountingBalanceService::class)->commissionRate($transaction->organization);

        return TicketPurchasePricingService::transactionMerchantSalesTotal($transaction, $rate);
    }

    private function setEventCreator(Event $event, ?string $adminUuid): void
    {
        DB::table('events')->where('uuid', $event->uuid)->update(['created_by' => $adminUuid]);
    }

    private function createTicket(Event $event, string $name): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => Str::slug($name, '_').'_'.Str::random(4),
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
    public function statsSumsMerchantSalesOfPaidOrganizationTransactionsPerPeriod(): void
    {
        $this->loginAnalyticsAdmin($this->organization);
        $event = $this->createEvent($this->organization, 'Stats Event');
        $other = $this->createEvent($this->otherOrganization, 'Foreign Event');

        $today = $this->createTransaction($event, 1000, '2026-05-20 10:00:00');
        $monday = $this->createTransaction($event, 400, '2026-05-18 10:00:00');
        $march = $this->createTransaction($event, 200, '2026-03-02 10:00:00');
        $this->createTransaction($event, 999, '2026-05-20 11:00:00', [
            'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
            'paid_at' => null,
        ]);
        $this->createTransaction($other, 5000, '2026-05-20 09:00:00');

        $todaySales = $this->merchantSales($today);
        $mondaySales = $this->merchantSales($monday);
        $marchSales = $this->merchantSales($march);
        $this->assertGreaterThan(0, $todaySales);

        $response = $this->organizerGet('/stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Organizer analytics stats')
            ->assertJsonPath('data.total_transactions', 4)
            ->assertJsonPath('data.total_events', 1);

        $this->assertEqualsWithDelta($todaySales + $mondaySales + $marchSales, $response->json('data.transactions'), 0.01);
        $this->assertEqualsWithDelta($todaySales + $mondaySales, $response->json('data.this_month_transactions'), 0.01);
        $this->assertEqualsWithDelta($todaySales + $mondaySales, $response->json('data.weekly_sales'), 0.01);
        $this->assertEqualsWithDelta($todaySales, $response->json('data.daily_sales'), 0.01);
    }

    #[Test]
    public function salesBuildsMonthlyTrendAndTopSellingEventsWithOrganizerFallbacks(): void
    {
        $this->loginAnalyticsAdmin(null, platformAdmin: true);

        $orgEvent = $this->createEvent($this->organization, 'Org Event');
        $adminEvent = $this->createEvent($this->organization, 'Admin Event', ['organization_uuid' => null]);
        $this->setEventCreator($adminEvent, $this->analyticsAdmin->uuid);
        $orphanEvent = $this->createEvent($this->organization, 'Orphan Event', ['organization_uuid' => null]);
        $this->setEventCreator($orphanEvent, null);
        $deletedEvent = $this->createEvent($this->organization, 'Deleted Event');

        $orgMay = $this->createTransaction($orgEvent, 1000, '2026-05-20 10:00:00');
        $orgMarch = $this->createTransaction($orgEvent, 500, '2026-03-10 10:00:00');
        $adminTx = $this->createTransaction($adminEvent, 300, '2026-05-19 09:00:00');
        $orphanTx = $this->createTransaction($orphanEvent, 200, '2026-05-01 09:00:00');
        $deletedTx = $this->createTransaction($deletedEvent, 100, '2026-04-15 09:00:00');
        $blankEventTx = $this->createTransaction(null, 50, '2026-05-02 09:00:00', ['event_uuid' => '']);
        $deletedEvent->delete();

        $response = $this->organizerGet('/sales')
            ->assertOk()
            ->assertJsonPath('message', 'Sales analytics data')
            ->assertJsonPath('data.sales_by_ticket_type', []);

        $may = $this->merchantSales($orgMay) + $this->merchantSales($adminTx) + $this->merchantSales($orphanTx)
            + $this->merchantSales($blankEventTx);
        $trend = $response->json('data.monthly_trend');
        $this->assertSame(['2026-03', '2026-04', '2026-05'], array_column($trend, 'year_month'));
        $this->assertSame(['March 2026', 'April 2026', 'May 2026'], array_column($trend, 'month'));
        $this->assertEqualsWithDelta($this->merchantSales($orgMarch), $trend[0]['total'], 0.01);
        $this->assertEqualsWithDelta($this->merchantSales($deletedTx), $trend[1]['total'], 0.01);
        $this->assertEqualsWithDelta($may, $trend[2]['total'], 0.01);

        $top = collect($response->json('data.top_selling_events'))->keyBy('event_uuid');
        $this->assertCount(4, $top, 'the transaction with a blank event uuid is skipped');
        $this->assertSame($orgEvent->uuid, $response->json('data.top_selling_events.0.event_uuid'));
        $this->assertSame('Analytics Org', $top[$orgEvent->uuid]['organizer']);
        $this->assertSame(2, $top[$orgEvent->uuid]['order_count']);
        $this->assertEqualsWithDelta(
            $this->merchantSales($orgMay) + $this->merchantSales($orgMarch),
            $top[$orgEvent->uuid]['total_sales'],
            0.01,
        );
        $this->assertSame('Ana Lytics', $top[$adminEvent->uuid]['organizer']);
        $this->assertSame('Orphan Event', $top[$orphanEvent->uuid]['event_name']);
        $this->assertSame('Unknown', $top[$orphanEvent->uuid]['organizer']);
        $this->assertSame('Unknown Event', $top[$deletedEvent->uuid]['event_name']);
        $this->assertSame('Unknown', $top[$deletedEvent->uuid]['organizer']);
    }

    #[Test]
    public function salesByTicketTypeFailsBecauseQuantityIsUndefined(): void
    {
        $this->loginAnalyticsAdmin($this->organization);
        $event = $this->createEvent($this->organization, 'Ticketed Event');
        $ticket = $this->createTicket($event, 'VIP');
        $transaction = $this->createTransaction($event, 500, '2026-05-20 10:00:00');
        $this->createOrder($transaction, $ticket->uuid, 2, 250);

        $response = $this->organizerGet('/sales');

        $response->assertStatus(500);
        $this->assertInstanceOf(ErrorException::class, $response->exception);
        $this->assertStringContainsString('Undefined variable $quantity', $response->exception->getMessage());
    }

    #[Test]
    public function salesByTicketTypeAggregatesLineMerchantSalesWhenWarningsAreNotReported(): void
    {
        $this->loginAnalyticsAdmin($this->organization);
        $event = $this->createEvent($this->organization, 'Ticketed Event');
        $vip = $this->createTicket($event, 'VIP');
        $regular = $this->createTicket($event, 'Regular');
        $orphanTicketUuid = (string) Str::uuid();

        $paid = $this->createTransaction($event, 750, '2026-05-20 10:00:00');
        $vipOrder = $this->createOrder($paid, $vip->uuid, 2, 250);
        $regularOrder = $this->createOrder($paid, $regular->uuid, 1, 250);
        $this->createOrder($paid, '', 1, 0);

        $zeroValue = $this->createTransaction($event, 0, '2026-05-19 10:00:00');
        $this->createOrder($zeroValue, $orphanTicketUuid, 1, 0);

        $this->createTransaction($event, 300, '2026-05-18 10:00:00');
        $this->createTransaction(null, 100, '2026-05-18 11:00:00');

        $paid = $paid->fresh(['transactionOrders', 'event']);
        $rate = app(OrganizerAccountingBalanceService::class)->commissionRate($this->organization);
        $expectedVip = TicketPurchasePricingService::lineMerchantSalesAmount(
            TicketPurchasePricingService::lineAmountsForPaidOrder($paid, $vipOrder->fresh(), $rate, 0.0),
        );
        $expectedRegular = TicketPurchasePricingService::lineMerchantSalesAmount(
            TicketPurchasePricingService::lineAmountsForPaidOrder($paid, $regularOrder->fresh(), $rate, 0.0),
        );

        $previous = error_reporting(E_ALL & ~E_WARNING);
        try {
            $response = $this->organizerGet('/sales');
        } finally {
            error_reporting($previous);
        }

        $response->assertOk();
        $rows = collect($response->json('data.sales_by_ticket_type'))->keyBy('event_ticket_uuid');

        $this->assertSame([$vip->uuid, $regular->uuid, $orphanTicketUuid], $rows->keys()->all());
        $this->assertSame('VIP', $rows[$vip->uuid]['ticket_name']);
        $this->assertSame('Ticketed Event', $rows[$vip->uuid]['event_name']);
        $this->assertEqualsWithDelta($expectedVip, $rows[$vip->uuid]['total_sales'], 0.01);
        $this->assertEqualsWithDelta($expectedRegular, $rows[$regular->uuid]['total_sales'], 0.01);
        $this->assertSame(0, $rows[$vip->uuid]['total_quantity'], 'quantity is never accumulated (undefined $quantity)');
        $this->assertSame('Unknown Ticket', $rows[$orphanTicketUuid]['ticket_name']);
        $this->assertSame('Unknown Event', $rows[$orphanTicketUuid]['event_name']);
        $this->assertFalse($rows->has(''), 'orders without a ticket uuid are skipped');
    }

    #[Test]
    public function eventsResolveOrganizerFromOrganizationCreatorOrUnknown(): void
    {
        $this->loginAnalyticsAdmin(null, platformAdmin: true);

        $orgEvent = $this->createEvent($this->organization, 'Org Event', [
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);
        $adminEvent = $this->createEvent($this->organization, 'Admin Event', ['organization_uuid' => null]);
        $this->setEventCreator($adminEvent, $this->analyticsAdmin->uuid);
        $orphanEvent = $this->createEvent($this->organization, 'Orphan Event', ['organization_uuid' => null]);
        $this->setEventCreator($orphanEvent, null);

        $orgTx = $this->createTransaction($orgEvent, 800, '2026-05-20 10:00:00');
        $this->createTransaction($adminEvent, 300, '2026-05-19 10:00:00', ['organization_uuid' => null]);

        $response = $this->organizerGet('/events')
            ->assertOk()
            ->assertJsonPath('message', 'Event analytics data')
            ->assertJsonPath('data.total_events', 3)
            ->assertJsonPath('data.status_breakdown.events_with_sales', 2);

        $top = collect($response->json('data.top_performing_events'))->keyBy('event_uuid');
        $this->assertSame('Analytics Org', $top[$orgEvent->uuid]['organizer']);
        $this->assertSame('published', $top[$orgEvent->uuid]['status']);
        $this->assertSame('Ana Lytics', $top[$adminEvent->uuid]['organizer']);
        $this->assertSame('', $top[$adminEvent->uuid]['status']);
        $this->assertSame('Unknown', $top[$orphanEvent->uuid]['organizer']);
        $this->assertEquals(0, $top[$orphanEvent->uuid]['total_revenue']);

        $all = collect($response->json('data.all_events'))->keyBy('event_uuid');
        $this->assertSame('Analytics Org', $all[$orgEvent->uuid]['organizer']);
        $this->assertTrue($all[$orgEvent->uuid]['is_published']);
        $this->assertSame('Ana Lytics', $all[$adminEvent->uuid]['organizer']);
        $this->assertSame('Unknown', $all[$orphanEvent->uuid]['organizer']);
        $this->assertSame(0, $all[$orphanEvent->uuid]['order_count']);
        $this->assertEqualsWithDelta($this->merchantSales($orgTx), $all[$orgEvent->uuid]['total_revenue'], 0.01);
        $this->assertSame(1, $all[$orgEvent->uuid]['order_count']);
    }

    #[Test]
    public function transactionRevenueSeriesIsScopedToTheOrganizerOrganization(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/transaction-revenue-series?granularity=daily&start_date=2026-05-18&end_date=2026-05-20')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Transaction revenue series');

        $expected = $this->repository()->getTransactionRevenueSeries(
            'daily',
            $this->organization->uuid,
            '2026-05-18',
            '2026-05-20',
            merchantRevenueOnly: true,
        );
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));
        $this->assertNotEquals(
            json_decode(json_encode($this->repository()->getTransactionRevenueSeries('daily', $this->otherOrganization->uuid, '2026-05-18', '2026-05-20', true)), true),
            $response->json('data'),
        );
    }

    #[Test]
    public function transactionRevenueSeriesDefaultsDateBoundsWhenOmitted(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/transaction-revenue-series?granularity=monthly')->assertOk();

        $expected = $this->repository()->getTransactionRevenueSeries('monthly', $this->organization->uuid, null, null, true);
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));
    }

    #[Test]
    public function exportTransactionRevenueSeriesStreamsOrganizerCsvWithoutAdminColumns(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->get(
            '/api/v1/analytics/organizer/transaction-revenue-series/export?granularity=daily&start_date=2026-05-18&end_date=2026-05-20',
            ['Authorization' => 'Bearer '.$this->token],
        );

        $response->assertOk();
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename="transactions_daily_2026-05-20_153000.csv"',
            $response->headers->get('Content-Disposition'),
        );
        $this->assertSame('Content-Disposition, Content-Type', $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        $expected = $this->repository()->exportTransactionRevenueSeriesCsv(
            'daily',
            $this->organization->uuid,
            '2026-05-18',
            '2026-05-20',
            includeAdminOnlyColumns: false,
        );
        $this->assertSame($expected, $response->getContent());
    }

    #[Test]
    public function exportTransactionRevenueSeriesDefaultsDateBoundsWhenOmitted(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->get(
            '/api/v1/analytics/organizer/transaction-revenue-series/export?granularity=yearly',
            ['Authorization' => 'Bearer '.$this->token],
        )->assertOk();

        $this->assertStringContainsString('transactions_yearly_', $response->headers->get('Content-Disposition'));
        $this->assertSame(
            $this->repository()->exportTransactionRevenueSeriesCsv('yearly', $this->organization->uuid, null, null, false),
            $response->getContent(),
        );
    }

    #[Test]
    public function successfulFailedTransactionCountsSeriesIsScopedToTheOrganizerOrganization(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/successful-failed-transaction-counts?granularity=daily&start_date=2026-05-18&end_date=2026-05-20')
            ->assertOk()
            ->assertJsonPath('message', 'Successful vs failed transaction counts');

        $expected = $this->repository()->getSuccessfulFailedTransactionCountsSeries('daily', $this->organization->uuid, '2026-05-18', '2026-05-20');
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));

        $default = $this->organizerGet('/successful-failed-transaction-counts?granularity=weekly')->assertOk();
        $this->assertEquals(
            json_decode(json_encode($this->repository()->getSuccessfulFailedTransactionCountsSeries('weekly', $this->organization->uuid, null, null)), true),
            $default->json('data'),
        );
    }

    #[Test]
    public function revenuePerEventSeriesIsScopedToTheOrganizerOrganization(): void
    {
        $events = $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/revenue-per-event-series?granularity=daily&start_date=2026-05-18&end_date=2026-05-20')
            ->assertOk()
            ->assertJsonPath('message', 'Revenue per event series');

        $expected = $this->repository()->getRevenuePerEventSeries('daily', $this->organization->uuid, '2026-05-18', '2026-05-20', true);
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));
        $this->assertStringNotContainsString($events['other']->uuid, json_encode($response->json('data')));

        $default = $this->organizerGet('/revenue-per-event-series?granularity=monthly')->assertOk();
        $this->assertEquals(
            json_decode(json_encode($this->repository()->getRevenuePerEventSeries('monthly', $this->organization->uuid, null, null, true)), true),
            $default->json('data'),
        );
    }

    #[Test]
    public function customerTypePieCountsNewAndRepeatCustomersOfTheOrganization(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/customer-type-pie')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer type totals');

        $expected = $this->repository()->getNewVsRepeatCustomerCounts($this->organization->uuid);
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));
    }

    #[Test]
    public function successfulFailedTransactionPieTotalsTheOrganizationEventTransactions(): void
    {
        $this->seedSeriesFixtures();
        $this->loginAnalyticsAdmin($this->organization);

        $response = $this->organizerGet('/successful-failed-transaction-pie')
            ->assertOk()
            ->assertJsonPath('message', 'Successful vs failed transaction totals');

        $expected = $this->repository()->getSuccessfulFailedTransactionTotals($this->organization->uuid);
        $this->assertEquals(json_decode(json_encode($expected), true), $response->json('data'));
    }

    #[Test]
    public function seriesEndpointsRequireAnOrganizationContext(): void
    {
        $this->loginAnalyticsAdmin(null, platformAdmin: true);

        $this->organizerGet('/customer-type-pie')
            ->assertForbidden()
            ->assertJsonPath('message', 'Organization context is required.');
    }
}
