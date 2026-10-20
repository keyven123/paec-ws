<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Models\Dataset;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Services\Platform\AdminPlatformPnLService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Note: commission percents in the fixtures are deliberately non-integers.
 * SQLite stores "12.00" in a NUMERIC column as INTEGER 12 and then evaluates
 * 12 / 100 as integer division, which MySQL does not.
 */
class AdminPlatformPnLServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    private AdminPlatformPnLService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMysqlDateFormat();
        Carbon::setTestNow($this->at('2026-03-18 14:00:00'));
        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => '10']);
        $this->service = new AdminPlatformPnLService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedLedger(): void
    {
        // March 2026 (current month-to-date)
        $this->ledgerRow([
            'payment_provider' => 'paypal',
            'gross_amount' => 1000,
            'markup_amount' => 50,
            'tax_and_fees' => 50,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 40,
            'payment_gateway_fixed_fee' => 10,
            'date_paid' => $this->at('2026-03-02 09:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => 'qrph',
            'gross_amount' => 2000,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 30,
            'date_paid' => $this->at('2026-03-05 09:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => 'card',
            'gross_amount' => 500,
            'ticketoc_commission_percent' => 20.5,
            'payment_gateway_commission' => 15,
            'date_paid' => $this->at('2026-03-06 09:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => 'mystery_wallet',
            'gross_amount' => 100,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 2,
            'date_paid' => $this->at('2026-03-07 09:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => null,
            'gross_amount' => 100,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 3,
            'date_paid' => $this->at('2026-03-08 09:00:00'),
        ]);
        $this->ledgerRow([
            'payment_provider' => 'upgrade',
            'gross_amount' => 300,
            'ticketoc_commission_percent' => 12.5,
            'date_paid' => $this->at('2026-03-09 09:00:00'),
        ]);
        // After "as of" day: outside every window.
        $this->ledgerRow(['gross_amount' => 9999, 'date_paid' => $this->at('2026-03-25 09:00:00')]);

        // Cross-month refund (original sale in February) → clawback 12.5% × 380 + 20 markup = 67.50
        $this->ledgerRow([
            'transaction_type' => TransactionCommission::TYPE['REFUND'],
            'payment_provider' => 'paypal',
            'gross_amount' => 400,
            'markup_amount' => 20,
            'ticketoc_commission_percent' => 12.5,
            'date_paid' => $this->at('2026-03-10 09:00:00'),
            'original_paid_at' => $this->at('2026-02-20 09:00:00'),
        ]);
        // Same-month refund and refund without original date: not clawed back.
        $this->ledgerRow([
            'transaction_type' => TransactionCommission::TYPE['REFUND'],
            'gross_amount' => 800,
            'ticketoc_commission_percent' => 12.5,
            'date_paid' => $this->at('2026-03-11 09:00:00'),
            'original_paid_at' => $this->at('2026-03-01 09:00:00'),
        ]);
        $this->ledgerRow([
            'transaction_type' => TransactionCommission::TYPE['REFUND'],
            'gross_amount' => 800,
            'ticketoc_commission_percent' => 12.5,
            'date_paid' => $this->at('2026-03-11 09:00:00'),
            'original_paid_at' => null,
        ]);

        // February 2026 (previous month)
        $this->ledgerRow([
            'payment_provider' => 'paypal',
            'gross_amount' => 1000,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 50,
            'date_paid' => $this->at('2026-02-14 09:00:00'),
        ]);

        // January 2026 (YTD only)
        $this->ledgerRow([
            'payment_provider' => 'paymongo',
            'payment_method' => 'gcash',
            'gross_amount' => 500,
            'ticketoc_commission_percent' => 12.5,
            'payment_gateway_commission' => 10,
            'date_paid' => $this->at('2026-01-20 09:00:00'),
        ]);

        // Refunded transactions (by updated_at)
        $refunded = $this->makeTx(['status' => Transaction::STATUS['REFUNDED'], 'total_amount' => 200]);
        $this->setTxColumns($refunded, ['updated_at' => '2026-03-12 10:00:00']);
        $olderRefund = $this->makeTx(['status' => Transaction::STATUS['REFUNDED'], 'total_amount' => 999]);
        $this->setTxColumns($olderRefund, ['updated_at' => '2025-12-12 10:00:00']);

        $this->makeOrg('Default Rate Org');
        $this->makeOrg('Override Org', 15);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByKey(array $rows): array
    {
        return array_column($rows, null, 'key');
    }

    #[Test]
    public function monthlyReportAggregatesTheLedgerAgainstThePreviousMonthAndYtd(): void
    {
        $this->seedLedger();

        $report = $this->service->buildReport($this->at('2026-03-18 14:00:00'), 'MONTHLY');

        $this->assertSame('2026-03-18T23:59:59+08:00', $report['as_of']);
        $this->assertSame('Asia/Manila', $report['timezone']);
        $this->assertSame(10.0, $report['platform_default_commission_percentage']);
        $this->assertSame('MONTHLY', $report['period']['type']);
        $this->assertSame('Mar 2026', $report['period']['current_label']);
        $this->assertSame('Feb 2026', $report['period']['previous_label']);
        $this->assertSame('MTD GMV', $report['period']['gmv_scope_hint']);
        $this->assertSame('2026-03-01T00:00:00+08:00', $report['period']['current_range']['start']);
        $this->assertSame('2026-03-18T23:59:59+08:00', $report['period']['current_range']['end']);
        $this->assertSame('2026-02-01T00:00:00+08:00', $report['period']['previous_range']['start']);
        $this->assertSame('2026-02-28T23:59:59+08:00', $report['period']['previous_range']['end']);

        $kpi = $report['kpi'];
        $this->assertSame(4000.0, $kpi['gross_sales_gmv']);
        $this->assertSame(4000.0, $kpi['gross_sales_gmv_as_of_today']);
        // platform fee 527.50 + markup 50
        $this->assertSame(577.5, $kpi['net_revenue_commission']);
        $this->assertSame(50.0, $kpi['tax_and_fees']);
        // mean of (default 10, override 15)
        $this->assertSame(12.5, $kpi['effective_take_rate_avg_merchant_pct']);
        $this->assertSame(14.44, $kpi['effective_take_rate_on_gmv_pct']);
        // 577.50 − gateway 100 − clawback 67.50
        $this->assertSame(410.0, $kpi['contribution_margin']);
        $this->assertSame(71.0, $kpi['contribution_margin_pct_of_revenue']);
        $this->assertSame(300.0, $kpi['mom_gross_sales_gmv_pct']);
        $this->assertSame(362.0, $kpi['mom_net_revenue_commission_pct']);
        $this->assertNull($kpi['mom_tax_and_fees_pct']);
        $this->assertSame(446.7, $kpi['mom_contribution_margin_pct']);
        // 527.50 / (4000 − 50 − 50)
        $this->assertSame(13.53, $kpi['weighted_avg_commission_pct_current_month']);

        $statement = $report['income_statement'];
        $this->assertSame('Mar 2026', $statement['current_month_label']);
        $this->assertSame('Feb 2026', $statement['previous_month_label']);
        $this->assertSame('YTD 2026', $statement['ytd_label']);
        // No PaymentGateway records are seeded, so no gateway provider/channel
        // rows appear between Chargebacks and Net sales.
        $this->assertSame(
            ['gmv', 'refunds', 'chargebacks', 'net_sales'],
            array_column($statement['rows'], 'key')
        );

        $rows = $this->rowsByKey($statement['rows']);
        $this->assertSame(
            ['current_month' => 4000.0, 'previous_month' => 1000.0, 'mom_pct' => 300.0, 'ytd' => 5500.0, 'pct_of_gmv' => 100.0],
            array_intersect_key($rows['gmv'], array_flip(['current_month', 'previous_month', 'mom_pct', 'ytd', 'pct_of_gmv']))
        );
        $this->assertSame(200.0, $rows['refunds']['current_month']);
        $this->assertNull($rows['refunds']['mom_pct']);
        $this->assertSame(-5.0, $rows['refunds']['pct_of_gmv']);
        $this->assertTrue($rows['refunds']['less']);
        $this->assertSame(0.0, $rows['chargebacks']['mom_pct']);
        $this->assertSame(0.0, $rows['chargebacks']['pct_of_gmv']);

        // Net sales = net GMV (3800) − gateway fees on sales-visible channels (100).
        $this->assertSame(3700.0, $rows['net_sales']['current_month']);
        $this->assertSame('summary', $rows['net_sales']['variant']);
        $this->assertSame(950.0, $rows['net_sales']['previous_month']);
        $this->assertSame(289.5, $rows['net_sales']['mom_pct']);
        $this->assertSame(5140.0, $rows['net_sales']['ytd']);
        $this->assertSame(92.5, $rows['net_sales']['pct_of_gmv']);

        $labels = array_column($statement['rows'], 'label', 'key');
        $this->assertSame('Gross sales', $labels['gmv']);
        $this->assertSame('Net sales', $labels['net_sales']);
    }

    #[Test]
    public function emptyLedgerProducesZeroKpisAndPlatformDefaults(): void
    {
        $report = $this->service->buildReport(null, AdminPlatformPnLService::PERIOD_MONTHLY);

        $kpi = $report['kpi'];
        $this->assertSame(0.0, $kpi['gross_sales_gmv']);
        $this->assertSame(0.0, $kpi['effective_take_rate_on_gmv_pct']);
        $this->assertSame(0.0, $kpi['contribution_margin_pct_of_revenue']);
        $this->assertSame(0.0, $kpi['mom_gross_sales_gmv_pct']);
        // No organizations: the average falls back to the platform default.
        $this->assertSame(10.0, $kpi['effective_take_rate_avg_merchant_pct']);
        // No net selling: weighted average falls back to the platform default.
        $this->assertSame(10.0, $kpi['weighted_avg_commission_pct_current_month']);

        $rows = $this->rowsByKey($report['income_statement']['rows']);
        $this->assertSame(
            ['gmv', 'refunds', 'chargebacks', 'net_sales'],
            array_column($report['income_statement']['rows'], 'key')
        );
        $this->assertSame(0.0, $rows['gmv']['pct_of_gmv']);
        $this->assertSame(0.0, $rows['net_sales']['current_month']);
        $this->assertSame(0.0, $rows['net_sales']['mom_pct']);
    }

    #[Test]
    public function dailyPeriodComparesAgainstYesterday(): void
    {
        $this->ledgerRow(['gross_amount' => 700, 'ticketoc_commission_percent' => 12.5, 'date_paid' => $this->at('2026-03-18 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 350, 'ticketoc_commission_percent' => 12.5, 'date_paid' => $this->at('2026-03-17 08:00:00')]);

        $report = $this->service->buildReport($this->at('2026-03-18 14:00:00'), AdminPlatformPnLService::PERIOD_DAILY);

        $this->assertSame('Mar 18, 2026', $report['period']['current_label']);
        $this->assertSame('Mar 17, 2026', $report['period']['previous_label']);
        $this->assertSame("Today's GMV", $report['period']['gmv_scope_hint']);
        $this->assertSame('2026-03-17T23:59:59+08:00', $report['period']['previous_range']['end']);
        $this->assertSame(700.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(100.0, $report['kpi']['mom_gross_sales_gmv_pct']);
        $this->assertSame(87.5, $report['kpi']['net_revenue_commission']);
    }

    #[Test]
    public function weeklyPeriodIsWeekToDateFromMonday(): void
    {
        $this->ledgerRow(['gross_amount' => 400, 'date_paid' => $this->at('2026-03-16 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 100, 'date_paid' => $this->at('2026-03-15 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 800, 'date_paid' => $this->at('2026-03-10 08:00:00')]);

        $report = $this->service->buildReport($this->at('2026-03-18 14:00:00'), AdminPlatformPnLService::PERIOD_WEEKLY);

        $this->assertSame('Mar 16 – Mar 18, 2026', $report['period']['current_label']);
        $this->assertSame('Mar 9 – Mar 11, 2026', $report['period']['previous_label']);
        $this->assertSame('Week-to-date GMV', $report['period']['gmv_scope_hint']);
        $this->assertSame(400.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(-50.0, $report['kpi']['mom_gross_sales_gmv_pct']);
    }

    #[Test]
    public function weeklyPeriodOnAMondayHasSingleDayLabels(): void
    {
        $report = $this->service->buildReport($this->at('2026-03-16 09:00:00'), AdminPlatformPnLService::PERIOD_WEEKLY);

        $this->assertSame('Mar 16, 2026', $report['period']['current_label']);
        $this->assertSame('Mar 9, 2026', $report['period']['previous_label']);
    }

    #[Test]
    public function yearlyPeriodComparesTheSameSpanLastYear(): void
    {
        $this->ledgerRow(['gross_amount' => 600, 'date_paid' => $this->at('2026-02-01 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 300, 'date_paid' => $this->at('2025-03-01 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 5000, 'date_paid' => $this->at('2025-06-01 08:00:00')]);

        $report = $this->service->buildReport($this->at('2026-03-18 14:00:00'), AdminPlatformPnLService::PERIOD_YEARLY);

        $this->assertSame('2026 YTD', $report['period']['current_label']);
        $this->assertSame('2025 (same span)', $report['period']['previous_label']);
        $this->assertSame('YTD GMV', $report['period']['gmv_scope_hint']);
        $this->assertSame('2025-01-01T00:00:00+08:00', $report['period']['previous_range']['start']);
        $this->assertSame(600.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(100.0, $report['kpi']['mom_gross_sales_gmv_pct']);
    }

    #[Test]
    public function customPeriodComparesAgainstTheEquallyLongPrecedingRange(): void
    {
        $this->ledgerRow(['gross_amount' => 1000, 'date_paid' => $this->at('2026-03-05 08:00:00')]);
        $this->ledgerRow(['gross_amount' => 250, 'date_paid' => $this->at('2026-02-25 08:00:00')]);

        $report = $this->service->buildReport(
            $this->at('2026-03-18 14:00:00'),
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-03-01 15:00:00'),
            $this->at('2026-03-10 08:00:00')
        );

        $this->assertSame('Mar 1 – Mar 10, 2026', $report['period']['current_label']);
        $this->assertSame('period GMV', $report['period']['gmv_scope_hint']);
        $this->assertSame('2026-03-01T00:00:00+08:00', $report['period']['current_range']['start']);
        $this->assertSame('2026-03-10T23:59:59+08:00', $report['period']['current_range']['end']);
        $this->assertSame('2026-02-28T23:59:59+08:00', $report['period']['previous_range']['end']);
        $this->assertSame(1000.0, $report['kpi']['gross_sales_gmv']);
        $this->assertSame(300.0, $report['kpi']['mom_gross_sales_gmv_pct']);
    }

    #[Test]
    public function customSingleDayPeriodUsesSingleDayLabels(): void
    {
        $report = $this->service->buildReport(
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-03-05 00:00:00'),
            $this->at('2026-03-05 00:00:00')
        );

        $this->assertSame('Mar 5, 2026', $report['period']['current_label']);
        $this->assertSame('2026-03-05T23:59:59+08:00', $report['period']['current_range']['end']);
        $this->assertSame('2026-03-04T23:59:59+08:00', $report['period']['previous_range']['end']);
    }

    #[Test]
    public function customPeriodSpanningYearsIncludesBothYears(): void
    {
        $report = $this->service->buildReport(
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2025-12-20 00:00:00'),
            $this->at('2026-01-05 00:00:00')
        );

        $this->assertSame('Dec 20, 2025 – Jan 5, 2026', $report['period']['current_label']);
    }

    #[Test]
    public function customPeriodRequiresBothDates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom period requires start and end dates.');

        $this->service->buildReport(null, AdminPlatformPnLService::PERIOD_CUSTOM, $this->at('2026-03-01'));
    }

    #[Test]
    public function customPeriodRejectsAStartAfterTheEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom start date must be on or before end date.');

        $this->service->buildReport(
            null,
            AdminPlatformPnLService::PERIOD_CUSTOM,
            $this->at('2026-03-10'),
            $this->at('2026-03-01')
        );
    }

    #[Test]
    public function unknownPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid period type.');

        $this->service->buildReport(null, 'quarterly');
    }

    #[Test]
    public function allowedPeriodsListsEverySupportedPeriod(): void
    {
        $this->assertSame(
            ['daily', 'weekly', 'monthly', 'yearly', 'custom'],
            AdminPlatformPnLService::allowedPeriods()
        );
    }
}
