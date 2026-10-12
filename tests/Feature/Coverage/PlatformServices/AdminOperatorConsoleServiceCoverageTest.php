<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\Dataset;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\Transaction;
use App\Services\Platform\AdminOperatorConsoleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminOperatorConsoleServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    private AdminOperatorConsoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow($this->at('2026-03-18 14:00:00'));
        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '10']);
        $this->service = new AdminOperatorConsoleService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function payout(Organization $org, string $status, float $amount, array $attrs = []): MerchantPayoutRequest
    {
        return MerchantPayoutRequest::create(array_merge([
            'organization_uuid' => $org->uuid,
            'organization_bank_uuid' => (string) Str::uuid(),
            'amount_requested' => $amount,
            'status' => $status,
        ], $attrs));
    }

    private function setPayoutCreatedAt(MerchantPayoutRequest $payout, string $createdAt): void
    {
        DB::table('merchant_payout_requests')->where('uuid', $payout->uuid)->update(['created_at' => $createdAt]);
    }

    #[Test]
    public function dailyKpisSumTodaysPaidSalesAndCompareTicketsWithYesterday(): void
    {
        $org = $this->makeOrg('Org Fifteen', 15);
        $event = $this->makeEvent($org, null);

        $k1 = $this->makeTx([
            'event_uuid' => $event->uuid,
            'organization_uuid' => $org->uuid,
            'total_amount' => 800,
            'paid_at' => $this->at('2026-03-18 10:00:00'),
        ]);
        $this->addOrder($k1, 2, 800);
        $this->addOrder($k1, -1, 0);
        // No event, no organization, no order lines: commission on the gross at the platform default.
        $this->makeTx(['event_uuid' => '', 'total_amount' => 200, 'paid_at' => $this->at('2026-03-18 11:00:00')]);
        // Unknown organization falls back to the platform default.
        $k3 = $this->makeTx([
            'event_uuid' => $event->uuid,
            'organization_uuid' => (string) Str::uuid(),
            'total_amount' => 100,
            'paid_at' => $this->at('2026-03-18 12:00:00'),
        ]);
        $this->addOrder($k3, 1, 100);
        // Refunded/cancelled today: excluded from sales, counted as pending refunds.
        $this->makeTx([
            'event_uuid' => $event->uuid,
            'status' => Transaction::STATUS['REFUNDED'],
            'total_amount' => 300,
            'paid_at' => $this->at('2026-03-18 09:00:00'),
        ]);
        $this->makeTx([
            'status' => Transaction::STATUS['CANCELLED'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'total_amount' => 150,
            'paid_at' => null,
        ]);
        // Yesterday: 4 tickets.
        $k6 = $this->makeTx(['total_amount' => 400, 'paid_at' => $this->at('2026-03-17 12:00:00')]);
        $this->addOrder($k6, 4, 400);

        $result = $this->service->build($this->at('2026-03-18 14:00:00'));

        $this->assertSame('2026-03-18T23:59:59+08:00', $result['as_of']);
        $this->assertSame('Asia/Manila', $result['timezone']);
        $this->assertSame('PHP', $result['currency']);
        $this->assertSame('daily', $result['period']['type']);
        $this->assertSame('Mar 18, 2026', $result['period']['current_label']);
        $this->assertSame('Mar 17, 2026', $result['period']['previous_label']);
        $this->assertSame('2026-03-17T00:00:00+08:00', $result['period']['previous_range']['start']);
        $this->assertSame('2026-03-17T23:59:59+08:00', $result['period']['previous_range']['end']);

        // 800 × 15% + 200 × 10% + 100 × 10% = 150 on 1100 gross
        $this->assertSame([
            'tickets_sold_today' => 3,
            'tickets_vs_avg_pct' => -25.0,
            'gross_today' => 1100.0,
            'gross_today_event_count' => 1,
            'net_revenue_today' => 150.0,
            'effective_take_rate_today' => 13.64,
            'refunds_pending_count' => 2,
            'refunds_pending_amount' => 450.0,
        ], $result['kpi']);
    }

    #[Test]
    public function kpisWithoutAnySalesHaveNoComparisonAndZeroTakeRate(): void
    {
        $result = $this->service->build(null, 'DAILY');

        $this->assertSame('daily', $result['period']['type']);
        $this->assertSame(0, $result['kpi']['tickets_sold_today']);
        $this->assertNull($result['kpi']['tickets_vs_avg_pct']);
        $this->assertSame(0.0, $result['kpi']['effective_take_rate_today']);
        $this->assertSame(0, $result['kpi']['refunds_pending_count']);
        $this->assertSame(0.0, $result['float_held']['total']);
        $this->assertCount(3, $result['float_held']['lines']);
        $this->assertSame([], $result['organizers']);
    }

    #[Test]
    public function previousWindowWithPaidSalesButNoTicketLinesHasNoComparison(): void
    {
        $this->makeTx(['total_amount' => 400, 'paid_at' => $this->at('2026-03-17 12:00:00')]);

        $result = $this->service->build($this->at('2026-03-18 14:00:00'));

        $this->assertNull($result['kpi']['tickets_vs_avg_pct']);
    }

    #[Test]
    public function weeklyPeriodRunsFromMondayToDate(): void
    {
        $result = $this->service->build($this->at('2026-03-18 14:00:00'), AdminOperatorConsoleService::PERIOD_WEEKLY);

        $this->assertSame('Mar 16 – Mar 18, 2026', $result['period']['current_label']);
        $this->assertSame('Mar 9 – Mar 11, 2026', $result['period']['previous_label']);
    }

    #[Test]
    public function weeklyPeriodOnAMondayUsesSingleDayLabels(): void
    {
        $result = $this->service->build($this->at('2026-03-16 14:00:00'), AdminOperatorConsoleService::PERIOD_WEEKLY);

        $this->assertSame('Mar 16, 2026', $result['period']['current_label']);
        $this->assertSame('Mar 9, 2026', $result['period']['previous_label']);
    }

    #[Test]
    public function monthlyPeriodIsMonthToDate(): void
    {
        $sale = $this->makeTx(['total_amount' => 900, 'paid_at' => $this->at('2026-03-02 12:00:00')]);
        $this->addOrder($sale, 3, 900);
        $this->makeTx(['total_amount' => 400, 'paid_at' => $this->at('2026-02-27 12:00:00')]);

        $result = $this->service->build($this->at('2026-03-18 14:00:00'), AdminOperatorConsoleService::PERIOD_MONTHLY);

        $this->assertSame('Mar 2026', $result['period']['current_label']);
        $this->assertSame('Feb 2026', $result['period']['previous_label']);
        $this->assertSame('2026-02-28T23:59:59+08:00', $result['period']['previous_range']['end']);
        $this->assertSame(900.0, $result['kpi']['gross_today']);
        $this->assertSame(3, $result['kpi']['tickets_sold_today']);
        $this->assertSame(90.0, $result['kpi']['net_revenue_today']);
    }

    #[Test]
    public function yearlyPeriodComparesTheSameSpanLastYear(): void
    {
        $result = $this->service->build($this->at('2026-03-18 14:00:00'), AdminOperatorConsoleService::PERIOD_YEARLY);

        $this->assertSame('2026 YTD', $result['period']['current_label']);
        $this->assertSame('2025 (same span)', $result['period']['previous_label']);
        $this->assertSame('2026-01-01T00:00:00+08:00', $result['period']['current_range']['start']);
        $this->assertSame('2025-03-18T23:59:59+08:00', $result['period']['previous_range']['end']);
    }

    #[Test]
    public function customPeriodUsesTheGivenRange(): void
    {
        $result = $this->service->build(
            $this->at('2026-03-18 14:00:00'),
            AdminOperatorConsoleService::PERIOD_CUSTOM,
            $this->at('2026-03-01 09:00:00'),
            $this->at('2026-03-10 09:00:00')
        );

        $this->assertSame('custom', $result['period']['type']);
        $this->assertSame('Mar 1 – Mar 10, 2026', $result['period']['current_label']);
        $this->assertSame('2026-03-01T00:00:00+08:00', $result['period']['current_range']['start']);
        $this->assertSame('2026-03-10T23:59:59+08:00', $result['period']['current_range']['end']);
        $this->assertSame('2026-02-28T23:59:59+08:00', $result['period']['previous_range']['end']);
    }

    #[Test]
    public function customPeriodRequiresBothDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom period requires start and end dates.');

        $this->service->build(null, AdminOperatorConsoleService::PERIOD_CUSTOM, null, $this->at('2026-03-01'));
    }

    #[Test]
    public function customPeriodRejectsAStartAfterTheEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom start date must be on or before end date.');

        $this->service->build(null, AdminOperatorConsoleService::PERIOD_CUSTOM, $this->at('2026-03-10'), $this->at('2026-03-01'));
    }

    #[Test]
    public function unknownPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period type.');

        $this->service->build(null, 'hourly');
    }

    #[Test]
    public function allowedPeriodsListsEverySupportedPeriod(): void
    {
        $this->assertSame(
            ['daily', 'weekly', 'monthly', 'yearly', 'custom'],
            AdminOperatorConsoleService::allowedPeriods()
        );
    }

    #[Test]
    public function floatHeldSplitsEscrowFromSettledPayoutsAndNetsApprovedPayouts(): void
    {
        $org15 = $this->makeOrg('Fifteen', 15);
        $org100 = $this->makeOrg('Hundred', 100);
        $future = $this->makeSchedule($this->makeEvent($org15, null), '2026-03-25');
        $past = $this->makeSchedule($this->makeEvent($org15, null), '2026-03-10');

        // Zero gross: ignored.
        $this->makeTx(['total_amount' => 0]);
        // Upcoming event → pre-event escrow (1000).
        $this->makeTx(['schedule_uuid' => $future->uuid, 'total_amount' => 1000, 'paid_at' => $this->at('2026-03-01 10:00:00')]);
        // Past event, paid Feb 10 → released Feb 28 → pending payout: 2000 − 15% = 1700.
        $this->makeTx([
            'schedule_uuid' => $past->uuid,
            'organization_uuid' => $org15->uuid,
            'total_amount' => 2000,
            'paid_at' => $this->at('2026-02-10 10:00:00'),
        ]);
        // No schedule, paid yesterday → settled but only released on Apr 15: not counted.
        $this->makeTx(['total_amount' => 500, 'paid_at' => $this->at('2026-03-17 10:00:00')]);
        // No schedule and no dates at all → escrow (300).
        $undated = $this->makeTx(['total_amount' => 300, 'paid_at' => null]);
        $this->setTxColumns($undated, ['created_at' => null]);
        // Settled with a 100% commission → nothing left for the merchant.
        $this->makeTx([
            'schedule_uuid' => $past->uuid,
            'organization_uuid' => $org100->uuid,
            'total_amount' => 700,
            'paid_at' => $this->at('2026-02-10 10:00:00'),
        ]);
        // No schedule, paid today → still escrow (250).
        $this->makeTx(['total_amount' => 250, 'paid_at' => $this->at('2026-03-18 09:00:00')]);
        // Not held: refunded or unpaid.
        $this->makeTx(['total_amount' => 999, 'status' => Transaction::STATUS['REFUNDED']]);
        $this->makeTx(['total_amount' => 999, 'payment_status' => Transaction::PAYMENT_STATUS['PENDING']]);

        $this->payout($org15, MerchantPayoutRequest::STATUS_APPROVED, 500);
        $this->payout($org15, MerchantPayoutRequest::STATUS_APPROVED, 200, ['void_at' => now()]);
        $this->payout($org15, MerchantPayoutRequest::STATUS_PENDING, 1000);

        $float = $this->service->build($this->at('2026-03-18 14:00:00'))['float_held'];

        // escrow 1550 + pending 1700 + reserve 32.50 − approved 500
        $this->assertSame(2782.5, $float['total']);
        $this->assertSame('cash held pending event completion', $float['subtitle']);
        $this->assertSame(4.6, $float['yield_rate_pct']);
        $this->assertEqualsWithDelta(128.0, $float['float_yield_annual'], 0.011);
        $this->assertSame([
            ['key' => 'pre_event_escrow', 'label' => 'Pre-event escrow', 'value' => 1550.0],
            ['key' => 'pending_payout', 'label' => 'Pending payout (settled events)', 'value' => 1700.0],
            ['key' => 'reserve', 'label' => 'Reserve (chargeback buffer)', 'value' => 32.5],
            ['key' => 'approved_payouts', 'label' => 'Less: approved merchant payouts', 'value' => -500.0],
        ], $float['lines']);
    }

    #[Test]
    public function floatHeldNeverGoesNegative(): void
    {
        $org = $this->makeOrg('Overpaid');
        $this->makeTx(['total_amount' => 100, 'paid_at' => $this->at('2026-03-18 09:00:00')]);
        $this->payout($org, MerchantPayoutRequest::STATUS_APPROVED, 5000);

        $float = $this->service->build($this->at('2026-03-18 14:00:00'))['float_held'];

        $this->assertSame(0.0, $float['total']);
        $this->assertSame(0.0, $float['float_yield_annual']);
        $this->assertSame(-5000.0, $float['lines'][3]['value']);
    }

    #[Test]
    public function buildSummarisesPayoutQueuesAndListsOrganizers(): void
    {
        $zeta = $this->makeOrg('Zeta Org');
        $alpha = $this->makeOrg('Alpha Org');

        $this->payout($alpha, MerchantPayoutRequest::STATUS_PENDING, 100);
        $this->payout($zeta, MerchantPayoutRequest::STATUS_PENDING, 200);
        $this->payout($alpha, MerchantPayoutRequest::STATUS_APPROVED, 300);
        $this->payout($alpha, MerchantPayoutRequest::STATUS_APPROVED, 50, ['void_at' => now()]);
        $this->payout($zeta, MerchantPayoutRequest::STATUS_VOID, 75, ['void_at' => now()]);
        $this->payout($zeta, MerchantPayoutRequest::STATUS_DECLINED, 40);

        $result = $this->service->build($this->at('2026-03-18 14:00:00'));

        $this->assertSame(['meta' => ['total' => 2]], $result['pending_payouts']);
        // approved + void rows are history; only non-voided approved amounts count.
        $this->assertSame(['meta' => ['total' => 3, 'total_amount' => 300.0]], $result['remittances']);
        $this->assertSame(['meta' => ['total' => 2, 'total_amount' => 350.0]], $result['approved_payout_requests']);
        $this->assertSame(['meta' => ['total' => 1, 'total_amount' => 40.0]], $result['declined_payout_requests']);
        $this->assertSame([
            ['uuid' => $alpha->uuid, 'name' => 'Alpha Org'],
            ['uuid' => $zeta->uuid, 'name' => 'Zeta Org'],
        ], $result['organizers']);
    }

    #[Test]
    public function pendingPayoutsAreListedNewestFirstWithBankDetails(): void
    {
        $org = $this->makeOrg('Bank Org');
        $event = $this->makeEvent($org, null, ['event_name' => 'Gala']);
        $bank = OrganizationBank::create([
            'organization_uuid' => $org->uuid,
            'account_type' => 'savings',
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Bank Org Inc',
            'bank_account_number' => '0011223344',
            'is_default' => true,
            'status' => 'active',
        ]);

        $withBank = $this->payout($org, MerchantPayoutRequest::STATUS_PENDING, 1200, [
            'organization_bank_uuid' => $bank->uuid,
            'event_uuid' => $event->uuid,
            'merchant_note' => 'Please release',
        ]);
        $this->setPayoutCreatedAt($withBank, '2026-03-17 10:00:00');
        $withoutBank = $this->payout($org, MerchantPayoutRequest::STATUS_PENDING, 300);
        $this->setPayoutCreatedAt($withoutBank, '2026-03-16 10:00:00');
        $this->payout($org, MerchantPayoutRequest::STATUS_APPROVED, 999);

        $page = $this->service->paginatedPendingPayouts(1, 1, $this->tz());

        // per_page is clamped to a minimum of 5
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 5, 'total' => 2], $page['meta']);
        $this->assertSame([$withBank->uuid, $withoutBank->uuid], array_column($page['rows'], 'uuid'));

        $first = $page['rows'][0];
        $this->assertSame('Bank Org', $first['organizer']);
        $this->assertSame('Gala', $first['event']);
        $this->assertSame('Please release', $first['merchant_note']);
        $this->assertSame(1200.0, $first['gross']);
        $this->assertSame(1200.0, $first['net_to_org']);
        $this->assertSame('2026-03-17', $first['release']);
        $this->assertSame('2026-03-17T10:00:00+08:00', $first['requested_at']);
        $this->assertSame('pending', $first['status']);
        $this->assertSame('Pending request', $first['status_label']);
        $this->assertSame([
            'uuid' => $bank->uuid,
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Bank Org Inc',
            'bank_account_number' => '0011223344',
            'is_default' => true,
            'status' => 'active',
        ], $first['bank']);

        $this->assertSame('—', $page['rows'][1]['event']);
        $this->assertNull($page['rows'][1]['bank']);
    }

    #[Test]
    public function approvedPayoutRequestsAreLabelledApproved(): void
    {
        $org = $this->makeOrg('Approved Org');
        $this->payout($org, MerchantPayoutRequest::STATUS_APPROVED, 400, [
            'admin_notes' => 'Sent via BDO',
            'merchant_note' => 'ignored',
            'processed_at' => $this->at('2026-03-15 09:00:00'),
            'currency' => 'USD',
        ]);
        $unprocessed = $this->payout($org, MerchantPayoutRequest::STATUS_APPROVED, 100, ['merchant_note' => 'Merchant says hi']);
        $this->setPayoutCreatedAt($unprocessed, '2026-03-10 09:00:00');
        $this->payout($org, MerchantPayoutRequest::STATUS_DECLINED, 999);

        $page = $this->service->paginatedPayoutRequests(MerchantPayoutRequest::STATUS_APPROVED, 0, 100, $this->tz());

        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 2, 'total_amount' => 500.0], $page['meta']);
        $this->assertSame('Approved', $page['rows'][0]['status_label']);
        $this->assertSame('approved', $page['rows'][0]['status']);
        $this->assertSame('Sent via BDO', $page['rows'][0]['notes']);
        $this->assertSame('USD', $page['rows'][0]['currency']);
        $this->assertSame('2026-03-15T09:00:00+08:00', $page['rows'][0]['processed_at']);
        $this->assertSame('Merchant says hi', $page['rows'][1]['notes']);
        $this->assertSame('2026-03-10T09:00:00+08:00', $page['rows'][1]['processed_at']);
    }

    #[Test]
    public function declinedPayoutRequestsAreLabelledDeclined(): void
    {
        $org = $this->makeOrg('Declined Org');
        $this->payout($org, MerchantPayoutRequest::STATUS_DECLINED, 80, ['admin_notes' => 'Wrong bank']);

        $page = $this->service->paginatedPayoutRequests(MerchantPayoutRequest::STATUS_DECLINED, 1, 10, $this->tz());

        $this->assertSame('Declined', $page['rows'][0]['status_label']);
        $this->assertSame('Declined Org', $page['rows'][0]['organizer']);
        $this->assertSame(80.0, $page['meta']['total_amount']);
    }

    #[Test]
    public function remittanceHistoryCanBeFilteredByOrganization(): void
    {
        $org = $this->makeOrg('Remit Org');
        $other = $this->makeOrg('Other Org');
        $event = $this->makeEvent($org, null, ['event_name' => 'Expo']);

        $approved = $this->payout($org, MerchantPayoutRequest::STATUS_APPROVED, 600, [
            'event_uuid' => $event->uuid,
            'processed_at' => $this->at('2026-03-12 09:00:00'),
        ]);
        $void = $this->payout($org, MerchantPayoutRequest::STATUS_VOID, 250, [
            'processed_at' => $this->at('2026-03-11 09:00:00'),
            'void_at' => $this->at('2026-03-14 09:00:00'),
        ]);
        $this->payout($other, MerchantPayoutRequest::STATUS_APPROVED, 900);
        $this->payout($org, MerchantPayoutRequest::STATUS_PENDING, 900);

        $filtered = $this->service->paginatedRemittances(1, 10, $this->tz(), $org->uuid);

        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 10, 'total' => 2, 'total_amount' => 600.0], $filtered['meta']);
        // Ordered by the most recent of void/processed/created.
        $this->assertSame([$void->uuid, $approved->uuid], array_column($filtered['rows'], 'uuid'));
        $this->assertSame('Void', $filtered['rows'][0]['status_label']);
        $this->assertSame('2026-03-14T09:00:00+08:00', $filtered['rows'][0]['void_at']);
        $this->assertSame('—', $filtered['rows'][0]['event']);
        $this->assertSame('Approved', $filtered['rows'][1]['status_label']);
        $this->assertSame('Expo', $filtered['rows'][1]['event']);
        $this->assertNull($filtered['rows'][1]['void_at']);

        $all = $this->service->paginatedRemittances(1, 10, $this->tz(), '');
        $this->assertSame(3, $all['meta']['total']);
        $this->assertSame(1500.0, $all['meta']['total_amount']);
    }
}
