<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Models\Dataset;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Services\Organizer\OrganizerAccountingReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerAccountingReportServiceCoverageTest extends TestCase
{
    use OrganizerServiceFixtures;
    use RefreshDatabase;

    private OrganizerAccountingReportService $service;

    private Organization $org;

    private Event $eventOne;

    private Event $eventTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-05-20 12:00:00');
        $this->service = app(OrganizerAccountingReportService::class);
        $this->org = $this->makeOrg('Report Merchant', 10);
        $this->eventOne = $this->makeEvent($this->org, 'Event One');
        $this->eventTwo = $this->makeEvent($this->org, 'Event Two');

        // Seven matured legacy transactions (Apr 1–7 → available Apr 30), merchant payout 90 × i.
        for ($i = 1; $i <= 7; $i++) {
            $this->makePaidTx($this->org, $this->eventOne, 100 * $i, sprintf('2026-04-0%d 10:00:00', $i), [
                'order_number' => 'ORD-MATURED-' . $i,
            ]);
        }

        // Matured but zero merchant payout: never listed.
        $this->makePaidTx($this->org, $this->eventOne, 0, '2026-04-08 10:00:00');

        // Pending (May 18 → available Jun 15).
        $this->makePaidTx($this->org, $this->eventTwo, 1000, '2026-05-18 10:00:00', [
            'order_number' => 'ORD-PENDING-1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function transactionsPaginatesAvailableRowsNewestFirst(): void
    {
        $page1 = $this->service->transactions($this->org->uuid, 'available', 1, 5);

        $this->assertSame('available', $page1['bucket']);
        $this->assertSame(10.0, $page1['commission_percentage']);
        $this->assertSame(10.0, $page1['effective_commission_percentage']);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 5, 'total' => 7], $page1['meta']);
        $this->assertSame(
            [630.0, 540.0, 450.0, 360.0, 270.0],
            array_map(fn (array $row) => (float) $row['total_payout'], $page1['transactions']),
        );

        $first = $page1['transactions'][0];
        $this->assertSame('ORD-MATURED-7', $first['order_number']);
        $this->assertSame('Event One', $first['event_name']);
        $this->assertSame($this->at('2026-04-07 10:00:00')->toIso8601String(), $first['paid_at']);
        $this->assertSame(10.0, $first['commission_percentage']);
        $this->assertSame('2026-04-30', $first['remittance_date']);
        $this->assertSame('available', $first['remittance_status']);

        $page2 = $this->service->transactions($this->org->uuid, 'available', 2, 5);

        $this->assertSame(2, $page2['meta']['current_page']);
        $this->assertSame(
            ['ORD-MATURED-2', 'ORD-MATURED-1'],
            array_column($page2['transactions'], 'order_number'),
        );
        $this->assertSame([180.0, 90.0], array_map(fn (array $row) => (float) $row['total_payout'], $page2['transactions']));
    }

    #[Test]
    public function transactionsClampsPageAndPerPage(): void
    {
        $beyond = $this->service->transactions($this->org->uuid, 'available', 99, 1);

        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 5, 'total' => 7], $beyond['meta']);
        $this->assertCount(2, $beyond['transactions']);

        $large = $this->service->transactions($this->org->uuid, 'available', 0, 500);

        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 7], $large['meta']);
        $this->assertCount(7, $large['transactions']);
    }

    #[Test]
    public function transactionsListsPendingBucketWithRemittanceSchedule(): void
    {
        $result = $this->service->transactions($this->org->uuid, 'pending', 1, 10);

        $this->assertSame(1, $result['meta']['total']);
        $row = $result['transactions'][0];
        $this->assertSame('ORD-PENDING-1', $row['order_number']);
        $this->assertSame('Event Two', $row['event_name']);
        $this->assertSame(900.0, (float) $row['total_payout']);
        $this->assertSame(100.0, (float) $row['platform_fee']);
        $this->assertSame('2026-06-15', $row['remittance_date']);
        $this->assertSame('pending', $row['remittance_status']);
    }

    #[Test]
    public function transactionsFiltersByEventAndHandlesEmptyResult(): void
    {
        $result = $this->service->transactions($this->org->uuid, 'available', 1, 10, $this->eventTwo->uuid);

        $this->assertSame([], $result['transactions']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 0], $result['meta']);

        $pending = $this->service->transactions($this->org->uuid, 'pending', 1, 10, $this->eventOne->uuid);
        $this->assertSame(0, $pending['meta']['total']);
    }

    #[Test]
    public function transactionsUsesPlatformRateWhenOrganizationHasNoOverride(): void
    {
        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '20']);
        $org = $this->makeOrg('Default Rate Merchant', null);
        $this->makePaidTx($org, $this->makeEvent($org, 'Default Event'), 1000, '2026-04-02 10:00:00');

        $result = $this->service->transactions($org->uuid, 'available', 1, 10);

        $this->assertNull($result['commission_percentage']);
        $this->assertSame(20.0, $result['effective_commission_percentage']);
        $this->assertSame(800.0, (float) $result['transactions'][0]['total_payout']);
        $this->assertSame(20.0, $result['transactions'][0]['commission_percentage']);
    }

    #[Test]
    public function remittanceBucketsGroupsAvailableRowsByReleaseSlot(): void
    {
        // Paid Apr 20 → released May 15 (release_15 slot of May).
        $this->makePaidTx($this->org, $this->eventOne, 50, '2026-04-20 10:00:00', ['order_number' => 'ORD-MID-APRIL']);

        $result = $this->service->remittanceBuckets($this->org->uuid, 'available');

        $this->assertSame('available', $result['bucket']);
        $this->assertSame(10.0, $result['commission_percentage']);
        $this->assertSame(['merchant_net_sum' => 2565.0, 'transaction_count' => 8], $result['totals']);
        $this->assertSame(['2026-05', '2026-04'], array_column($result['months'], 'key'));

        [$may, $april] = $result['months'];

        $this->assertSame('May 2026', $may['title']);
        $this->assertSame('2026-05-15', $may['release_15']['release_date']);
        $this->assertSame(45.0, $may['release_15']['total_merchant_net']);
        $this->assertSame(['ORD-MID-APRIL'], array_column($may['release_15']['transactions'], 'order_number'));
        $this->assertSame(['release_date' => null, 'total_merchant_net' => 0.0, 'transactions' => []], $may['release_30']);

        $this->assertSame('April 2026', $april['title']);
        $this->assertSame('2026-04-30', $april['release_30']['release_date']);
        $this->assertSame(2520.0, $april['release_30']['total_merchant_net']);
        $this->assertSame(
            ['ORD-MATURED-7', 'ORD-MATURED-6', 'ORD-MATURED-5', 'ORD-MATURED-4', 'ORD-MATURED-3', 'ORD-MATURED-2', 'ORD-MATURED-1'],
            array_column($april['release_30']['transactions'], 'order_number'),
        );
        $this->assertNull($april['release_15']['release_date']);
    }

    #[Test]
    public function remittanceBucketsListsPendingBucketForEvent(): void
    {
        $result = $this->service->remittanceBuckets($this->org->uuid, 'pending', $this->eventTwo->uuid);

        $this->assertSame(['merchant_net_sum' => 900.0, 'transaction_count' => 1], $result['totals']);
        $this->assertSame('2026-06', $result['months'][0]['key']);
        $this->assertSame('June 2026', $result['months'][0]['title']);
        $this->assertSame(900.0, $result['months'][0]['release_15']['total_merchant_net']);

        $empty = $this->service->remittanceBuckets($this->org->uuid, 'pending', $this->eventOne->uuid);
        $this->assertSame(['merchant_net_sum' => 0.0, 'transaction_count' => 0], $empty['totals']);
        $this->assertSame([], $empty['months']);
    }

    #[Test]
    public function transactionWithoutAnyTimestampIsListedAsAvailableWithScheduleFromToday(): void
    {
        $org = $this->makeOrg('Undated Merchant', 10);
        $tx = $this->makePaidTx($org, $this->makeEvent($org, 'Undated Event'), 200, '2026-04-02 10:00:00', [
            'order_number' => 'ORD-UNDATED',
        ]);
        $this->setTxColumns($tx, ['paid_at' => null, 'created_at' => null]);

        $buckets = $this->service->remittanceBuckets($org->uuid, 'available');

        $this->assertSame(['merchant_net_sum' => 180.0, 'transaction_count' => 1], $buckets['totals']);
        $this->assertSame('2026-06', $buckets['months'][0]['key']);
        $row = $buckets['months'][0]['release_15']['transactions'][0];
        $this->assertSame('ORD-UNDATED', $row['order_number']);
        $this->assertNull($row['paid_at']);
        $this->assertSame('2026-06-15', $row['remittance_date']);
        $this->assertSame('pending', $row['remittance_status']);

        $listed = $this->service->transactions($org->uuid, 'available', 1, 5);
        $this->assertSame(1, $listed['meta']['total']);
        $this->assertNull($listed['transactions'][0]['paid_at']);

        $this->assertSame(0, $this->service->transactions($org->uuid, 'pending', 1, 5)['meta']['total']);
    }

    #[Test]
    public function summaryCombinesBalancesAndApprovedCashouts(): void
    {
        $this->makePayout($this->org, $this->eventOne, 300, MerchantPayoutRequest::STATUS_APPROVED, ['processed_at' => now()]);
        $this->makePayout($this->org, $this->eventOne, 400, MerchantPayoutRequest::STATUS_APPROVED, ['void_at' => now()]);

        $summary = $this->service->summary($this->org->uuid);

        $this->assertSame([
            'available' => 2220.0,
            'available_for_cashout' => 2220.0,
            'matured_total_payout' => 2520.0,
            'pending' => 900.0,
            'pending_total_payout' => 900.0,
            'total_cashout' => 300.0,
            'commission_percentage' => 10.0,
            'effective_commission_percentage' => 10.0,
            'currency' => 'PHP',
        ], $summary);

        $eventTwo = $this->service->summary($this->org->uuid, $this->eventTwo->uuid);
        $this->assertSame(0.0, $eventTwo['available']);
        $this->assertSame(900.0, $eventTwo['pending']);
        $this->assertSame(0.0, $eventTwo['total_cashout']);
    }

    #[Test]
    public function payoutRequestsSplitsByStatusAndExcludesVoidedApprovals(): void
    {
        $this->makePayout($this->org, $this->eventOne, 100, MerchantPayoutRequest::STATUS_PENDING, ['merchant_note' => 'please']);
        $this->makePayout($this->org, $this->eventOne, 200, MerchantPayoutRequest::STATUS_APPROVED, ['processed_at' => now()]);
        $this->makePayout($this->org, $this->eventOne, 250, MerchantPayoutRequest::STATUS_APPROVED, ['void_at' => now()]);
        $this->makePayout($this->org, null, 300, MerchantPayoutRequest::STATUS_DECLINED, ['admin_notes' => 'no']);

        $all = $this->service->payoutRequests($this->org->uuid, perPage: 1);

        $this->assertSame(5, $all['pending']['meta']['per_page']);
        $this->assertSame(1, $all['pending']['meta']['total']);
        $this->assertSame('Event One', $all['pending']['rows'][0]['event_name']);
        $this->assertSame('please', $all['pending']['rows'][0]['merchant_note']);
        $this->assertSame(1, $all['success']['meta']['total']);
        $this->assertSame(200.0, $all['success']['rows'][0]['amount_requested']);
        $this->assertSame(now()->timezone($this->tz())->toIso8601String(), $all['success']['rows'][0]['processed_at']);
        $this->assertSame(1, $all['declined']['meta']['total']);
        $this->assertNull($all['declined']['rows'][0]['event_name']);
        $this->assertSame('no', $all['declined']['rows'][0]['admin_notes']);
        $this->assertNull($all['declined']['rows'][0]['processed_at']);

        $filtered = $this->service->payoutRequests($this->org->uuid, $this->eventTwo->uuid);
        $this->assertSame(0, $filtered['pending']['meta']['total']);
        $this->assertSame(0, $filtered['success']['meta']['total']);
        $this->assertSame(0, $filtered['declined']['meta']['total']);
    }
}
