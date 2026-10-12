<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\TransactionCommission;
use App\Services\Organizer\OrganizerPnLService;
use App\Services\Platform\AdminPlatformPnLService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerPnLServiceCoverageTest extends TestCase
{
    use OrganizerServiceFixtures;
    use RefreshDatabase;

    private OrganizerPnLService $service;

    private Organization $org;

    private Event $eventOne;

    private Event $eventTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAt('2026-05-20 12:00:00');
        $this->service = app(OrganizerPnLService::class);
        $this->org = $this->makeOrg('PnL Merchant', 10);
        $this->eventOne = $this->makeEvent($this->org, 'PnL One');
        $this->eventTwo = $this->makeEvent($this->org, 'PnL Two');

        // Current month (May 1–20).
        $this->ledgerRow($this->org, $this->eventOne, '2026-05-05 10:00:00', 1000, 100, 900, 50);
        $this->ledgerRow($this->org, $this->eventTwo, '2026-05-10 10:00:00', 500, 50, 450);
        $this->ledgerRow($this->org, $this->eventOne, '2026-05-12 10:00:00', -200, 0, 0, 0, [
            'transaction_type' => TransactionCommission::TYPE['REFUND'],
        ]);
        // Previous month.
        $this->ledgerRow($this->org, $this->eventOne, '2026-04-15 10:00:00', 1200, 100, 1100, 20);
        // Earlier this year (YTD only).
        $this->ledgerRow($this->org, $this->eventOne, '2026-01-10 10:00:00', 300, 30, 270);
        // Same span last year (yearly comparison only).
        $this->ledgerRow($this->org, $this->eventOne, '2025-03-01 10:00:00', 600, 60, 540);
        // Excluded: after "as of", other ledger types, other organization.
        $this->ledgerRow($this->org, $this->eventOne, '2026-05-21 10:00:00', 9999, 999, 9000);
        $this->ledgerRow($this->org, $this->eventOne, '2026-05-06 10:00:00', 9999, 999, 9000, 0, [
            'transaction_type' => TransactionCommission::TYPE['CHARGEBACK'],
        ]);
        $other = $this->makeOrg('Other PnL Merchant', 10);
        $this->ledgerRow($other, $this->makeEvent($other, 'Other'), '2026-05-06 10:00:00', 9999, 999, 9000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function monthlyReportComputesKpisAndIncomeStatement(): void
    {
        $this->makePayout($this->org, null, 300, MerchantPayoutRequest::STATUS_APPROVED, ['processed_at' => now()]);

        $report = $this->service->buildReport($this->org->uuid);

        $this->assertSame($this->at('2026-05-20 23:59:59')->toIso8601String(), $report['as_of']);
        $this->assertSame($this->tz(), $report['timezone']);
        $this->assertSame($this->org->uuid, $report['organization_uuid']);
        $this->assertNull($report['event_uuid']);
        $this->assertSame(10.0, $report['effective_commission_percentage']);
        $this->assertSame([
            'type' => 'monthly',
            'current_label' => 'May 2026',
            'previous_label' => 'Apr 2026',
            'gmv_scope_hint' => 'MTD GMV',
        ], $report['period']);

        $this->assertSame([
            'gross_sales_gmv' => 1500.0,
            'net_merchant_revenue' => 1350.0,
            'platform_commission' => 150.0,
            'tax_and_fees' => 50.0,
            'effective_commission_on_gmv_pct' => 10.0,
            'net_merchant_revenue_pct_of_gmv' => 90.0,
            'available_for_payout' => -300.0,
            'pending_remittance' => 0.0,
            'total_cashout' => 300.0,
            'mom_gross_sales_gmv_pct' => 25.0,
            'mom_net_merchant_revenue_pct' => 22.7,
            'mom_platform_commission_pct' => 50.0,
        ], $report['kpi']);

        $statement = $report['income_statement'];
        $this->assertSame('May 2026', $statement['current_month_label']);
        $this->assertSame('Apr 2026', $statement['previous_month_label']);
        $this->assertSame('YTD 2026', $statement['ytd_label']);
        $this->assertSame([
            [
                'key' => 'gmv', 'label' => 'Gross sales (GMV)', 'less' => false, 'variant' => 'standard',
                'current_month' => 1500.0, 'previous_month' => 1200.0, 'mom_pct' => 25.0, 'ytd' => 3000.0, 'pct_of_gmv' => 100.0,
            ],
            [
                'key' => 'refunds', 'label' => 'Refunds', 'less' => true, 'variant' => 'standard',
                'current_month' => 200.0, 'previous_month' => 0.0, 'mom_pct' => null, 'ytd' => 200.0, 'pct_of_gmv' => -13.3,
            ],
            [
                'key' => 'net_gmv', 'label' => 'Net GMV', 'less' => false, 'variant' => 'summary',
                'current_month' => 1300.0, 'previous_month' => 1200.0, 'mom_pct' => 8.3, 'ytd' => 2800.0, 'pct_of_gmv' => 86.7,
            ],
            [
                'key' => 'platform_commission', 'label' => 'Platform commission', 'less' => true, 'variant' => 'commission',
                'current_month' => 150.0, 'previous_month' => 100.0, 'mom_pct' => 50.0, 'ytd' => 280.0, 'pct_of_gmv' => -10.0,
            ],
            [
                'key' => 'net_merchant_revenue', 'label' => 'Net merchant revenue', 'less' => false, 'variant' => 'margin',
                'current_month' => 1350.0, 'previous_month' => 1100.0, 'mom_pct' => 22.7, 'ytd' => 2720.0, 'pct_of_gmv' => 90.0,
            ],
        ], $statement['rows']);
    }

    #[Test]
    public function eventFilterScopesMetricsAndReportsNullChangeFromZero(): void
    {
        $report = $this->service->buildReport($this->org->uuid, eventUuid: $this->eventTwo->uuid);

        $this->assertSame($this->eventTwo->uuid, $report['event_uuid']);
        $this->assertSame(500.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(450.0, $report['kpi']['net_merchant_revenue']);
        $this->assertNull($report['kpi']['mom_gross_sales_gmv_pct']);
        $this->assertNull($report['kpi']['mom_platform_commission_pct']);

        $refunds = $report['income_statement']['rows'][1];
        $this->assertSame(0.0, $refunds['current_month']);
        $this->assertSame(0.0, $refunds['mom_pct']);
        // A zero deduction is not sign-flipped.
        $this->assertSame(0.0, $refunds['pct_of_gmv']);
        $this->assertSame(500.0, $report['income_statement']['rows'][0]['ytd']);
    }

    #[Test]
    public function emptyOrganizationReportsZeroPercentagesInsteadOfDividingByZero(): void
    {
        $empty = $this->makeOrg('Empty PnL Merchant', null);

        $report = $this->service->buildReport($empty->uuid);

        $this->assertSame(0.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(0.0, $report['kpi']['effective_commission_on_gmv_pct']);
        $this->assertSame(0.0, $report['kpi']['net_merchant_revenue_pct_of_gmv']);
        $this->assertSame(0.0, $report['kpi']['mom_gross_sales_gmv_pct']);
        $this->assertSame(0.0, $report['effective_commission_percentage']);
        foreach ($report['income_statement']['rows'] as $row) {
            $this->assertSame(0.0, $row['pct_of_gmv']);
            $this->assertSame(0.0, $row['mom_pct']);
        }
    }

    #[Test]
    public function dailyPeriodComparesAgainstPreviousDay(): void
    {
        $report = $this->service->buildReport(
            $this->org->uuid,
            $this->at('2026-05-05 09:00:00'),
            AdminPlatformPnLService::PERIOD_DAILY,
        );

        $this->assertSame([
            'type' => 'daily',
            'current_label' => 'May 5, 2026',
            'previous_label' => 'May 4, 2026',
            'gmv_scope_hint' => "Today's GMV",
        ], $report['period']);
        $this->assertSame(1000.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(50.0, $report['kpi']['tax_and_fees']);
        $this->assertNull($report['kpi']['mom_gross_sales_gmv_pct']);
        $this->assertSame($this->at('2026-05-05 23:59:59')->toIso8601String(), $report['as_of']);
    }

    #[Test]
    public function weeklyPeriodUsesWeekToDateRanges(): void
    {
        // 2026-05-20 is a Wednesday: Mon May 18 – Wed May 20 vs Mon May 11 – Wed May 13.
        $report = $this->service->buildReport($this->org->uuid, null, AdminPlatformPnLService::PERIOD_WEEKLY);

        $this->assertSame('May 18 – May 20, 2026', $report['period']['current_label']);
        $this->assertSame('May 11 – May 13, 2026', $report['period']['previous_label']);
        $this->assertSame('Week-to-date GMV', $report['period']['gmv_scope_hint']);
        $this->assertSame(0.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(200.0, $report['income_statement']['rows'][1]['previous_month']);
    }

    #[Test]
    public function weeklyPeriodOnMondayUsesSingleDayLabels(): void
    {
        $report = $this->service->buildReport(
            $this->org->uuid,
            $this->at('2026-05-11 08:00:00'),
            AdminPlatformPnLService::PERIOD_WEEKLY,
        );

        $this->assertSame('May 11, 2026', $report['period']['current_label']);
        $this->assertSame('May 4, 2026', $report['period']['previous_label']);
        $this->assertSame(0.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(0.0, $report['income_statement']['rows'][0]['previous_month']);
    }

    #[Test]
    public function yearlyPeriodComparesYearToDateWithSameSpanLastYear(): void
    {
        $report = $this->service->buildReport($this->org->uuid, null, 'YEARLY');

        $this->assertSame([
            'type' => 'YEARLY',
            'current_label' => '2026 YTD',
            'previous_label' => '2025 (same span)',
            'gmv_scope_hint' => 'YTD GMV',
        ], $report['period']);
        $this->assertSame(3000.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(400.0, $report['kpi']['mom_gross_sales_gmv_pct']);
        $this->assertSame(600.0, $report['income_statement']['rows'][0]['previous_month']);
    }

    #[Test]
    public function customPeriodComparesAgainstEqualLengthPrecedingWindow(): void
    {
        $report = $this->service->buildReport(
            $this->org->uuid,
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-05-05 15:00:00'),
            $this->at('2026-05-10 08:00:00'),
        );

        $this->assertSame([
            'type' => 'custom',
            'current_label' => 'May 5 – May 10, 2026',
            'previous_label' => 'Apr 29 – May 4, 2026',
            'gmv_scope_hint' => 'period GMV',
        ], $report['period']);
        $this->assertSame(1500.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(0.0, $report['income_statement']['rows'][0]['previous_month']);
    }

    #[Test]
    public function customPeriodLabelsSingleDayAndCrossYearRanges(): void
    {
        $single = $this->service->buildReport(
            $this->org->uuid,
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-05-05'),
            $this->at('2026-05-05'),
        );
        $this->assertSame('May 5, 2026', $single['period']['current_label']);
        $this->assertSame('May 4, 2026', $single['period']['previous_label']);
        $this->assertSame(1000.0, $single['kpi']['gross_sales_gmv']);

        $crossYear = $this->service->buildReport(
            $this->org->uuid,
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2025-12-30'),
            $this->at('2026-01-10'),
        );
        $this->assertSame('Dec 30, 2025 – Jan 10, 2026', $crossYear['period']['current_label']);
        $this->assertSame(300.0, $crossYear['kpi']['gross_sales_gmv']);
    }

    #[Test]
    public function customPeriodRequiresBothDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom period requires start and end dates.');

        $this->service->buildReport($this->org->uuid, null, AdminPlatformPnLService::PERIOD_CUSTOM, $this->at('2026-05-01'));
    }

    #[Test]
    public function customPeriodRejectsStartAfterEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom start date must be on or before end date.');

        $this->service->buildReport(
            $this->org->uuid,
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-05-10'),
            $this->at('2026-05-01'),
        );
    }

    #[Test]
    public function unknownPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period type.');

        $this->service->buildReport($this->org->uuid, null, 'quarterly');
    }
}
