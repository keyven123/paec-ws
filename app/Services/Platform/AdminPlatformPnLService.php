<?php

namespace App\Services\Platform;

use App\Models\Dataset;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class AdminPlatformPnLService
{
    /**
     * PayMongo payment methods as separate P&L lines (aligned with admin rate settings).
     *
     * @var list<string>
     */
    private const PAYMONGO_INCOME_STATEMENT_METHODS = [
        'qr_ph',
        'card',
        'gcash',
        'grab_pay',
        'shopee_pay',
        'billease',
        'paymaya',
        'dob',
        'brankas',
    ];

    public function __construct() {}

    public const PERIOD_DAILY = 'daily';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_YEARLY = 'yearly';

    public const PERIOD_CUSTOM = 'custom';

    /**
     * @return list<string>
     */
    public static function allowedPeriods(): array
    {
        return [
            self::PERIOD_DAILY,
            self::PERIOD_WEEKLY,
            self::PERIOD_MONTHLY,
            self::PERIOD_YEARLY,
            self::PERIOD_CUSTOM,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(
        ?Carbon $asOf = null,
        string $period = self::PERIOD_MONTHLY,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null
    ): array {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $asOf = ($asOf ?? Carbon::now($tz))->copy()->timezone($tz);
        $endOfToday = $asOf->copy()->endOfDay();

        $windows = $this->resolvePeriodWindows($period, $asOf, $customStart, $customEnd);
        $curStart = $windows['cur_start'];
        $curEnd = $windows['cur_end'];
        $prevStart = $windows['prev_start'];
        $prevEnd = $windows['prev_end'];

        $platformDefault = Dataset::merchantCommissionPercent();

        $ytdStart = $asOf->copy()->startOfYear();
        $ytdEnd = $endOfToday;

        $cur = $this->periodMetrics($curStart, $curEnd, $platformDefault);
        $prev = $this->periodMetrics($prevStart, $prevEnd, $platformDefault);
        $ytd = $this->periodMetrics($ytdStart, $ytdEnd, $platformDefault);

        $avgMerchantPctCur = $this->averageAllMerchantsEffectiveCommissionPercent($platformDefault);

        $momGmv = $this->percentChange($prev['gross_sales_gmv'], $cur['gross_sales_gmv']);
        $momCommission = $this->percentChange($prev['commission_revenue'], $cur['commission_revenue']);
        $momTaxAndFees = $this->percentChange($prev['tax_and_fees'], $cur['tax_and_fees']);
        $momMargin = $this->percentChange($prev['contribution_margin'], $cur['contribution_margin']);

        $effectiveOnGmv = $cur['gross_sales_gmv'] > 0
            ? round($cur['commission_revenue'] / $cur['gross_sales_gmv'] * 100.0, 2)
            : 0.0;

        $marginPctOfRevenue = $cur['commission_revenue'] > 0
            ? round($cur['contribution_margin'] / $cur['commission_revenue'] * 100.0, 1)
            : 0.0;

        $periodGmv = round($cur['gross_sales_gmv'], 2);

        return [
            'as_of' => $endOfToday->toIso8601String(),
            'timezone' => $tz,
            'platform_default_commission_percentage' => round($platformDefault, 2),
            'period' => [
                'type' => $period,
                'current_label' => $windows['current_label'],
                'previous_label' => $windows['previous_label'],
                'gmv_scope_hint' => $windows['gmv_scope_hint'],
                'current_range' => [
                    'start' => $curStart->toIso8601String(),
                    'end' => $curEnd->toIso8601String(),
                ],
                'previous_range' => [
                    'start' => $prevStart->toIso8601String(),
                    'end' => $prevEnd->toIso8601String(),
                ],
            ],
            'kpi' => [
                'gross_sales_gmv' => $periodGmv,
                /** @deprecated Use gross_sales_gmv (period total). Kept for older clients. */
                'gross_sales_gmv_as_of_today' => $periodGmv,
                'net_revenue_commission' => round($cur['commission_revenue'], 2),
                'tax_and_fees' => round($cur['tax_and_fees'], 2),
                'effective_take_rate_avg_merchant_pct' => round($avgMerchantPctCur, 2),
                'effective_take_rate_on_gmv_pct' => $effectiveOnGmv,
                'contribution_margin' => round($cur['contribution_margin'], 2),
                'contribution_margin_pct_of_revenue' => $marginPctOfRevenue,
                'mom_gross_sales_gmv_pct' => $momGmv,
                'mom_net_revenue_commission_pct' => $momCommission,
                'mom_tax_and_fees_pct' => $momTaxAndFees,
                'mom_contribution_margin_pct' => $momMargin,
                'weighted_avg_commission_pct_current_month' => $cur['weighted_avg_commission_pct'],
            ],
            'income_statement' => [
                'current_month_label' => $windows['current_label'],
                'previous_month_label' => $windows['previous_label'],
                'ytd_label' => 'YTD '.$asOf->year,
                'rows' => $this->incomeStatementRows($cur, $prev, $ytd),
            ],
        ];
    }

    /**
     * @return array{
     *     cur_start: Carbon,
     *     cur_end: Carbon,
     *     prev_start: Carbon,
     *     prev_end: Carbon,
     *     current_label: string,
     *     previous_label: string,
     *     gmv_scope_hint: string
     * }
     */
    private function resolvePeriodWindows(
        string $period,
        Carbon $asOf,
        ?Carbon $customStart,
        ?Carbon $customEnd
    ): array {
        $period = strtolower($period);

        if ($period === self::PERIOD_CUSTOM) {
            if ($customStart === null || $customEnd === null) {
                throw new InvalidArgumentException('Custom period requires start and end dates.');
            }
            $curStart = $customStart->copy()->startOfDay();
            $curEnd = $customEnd->copy()->endOfDay();
            if ($curStart->gt($curEnd)) {
                throw new InvalidArgumentException('Custom start date must be on or before end date.');
            }
            $nDays = $curStart->diffInDays($curEnd) + 1;
            $prevEnd = $curStart->copy()->subDay()->endOfDay();
            $prevStart = $prevEnd->copy()->subDays(max(0, $nDays - 1))->startOfDay();

            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $this->formatCustomRangeLabel($curStart, $curEnd),
                'previous_label' => $this->formatCustomRangeLabel($prevStart, $prevEnd),
                'gmv_scope_hint' => 'period GMV',
            ];
        }

        if ($period === self::PERIOD_DAILY) {
            $curStart = $asOf->copy()->startOfDay();
            $curEnd = $asOf->copy()->endOfDay();
            $prevStart = $curStart->copy()->subDay()->startOfDay();
            $prevEnd = $curStart->copy()->subSecond();

            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $curStart->format('M j, Y'),
                'previous_label' => $prevStart->format('M j, Y'),
                'gmv_scope_hint' => "Today's GMV",
            ];
        }

        if ($period === self::PERIOD_WEEKLY) {
            $curStart = $asOf->copy()->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $curEnd = $asOf->copy()->endOfDay();
            $prevStart = $curStart->copy()->subWeek();
            $prevEnd = $curEnd->copy()->subWeek();

            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $this->formatShortRangeLabel($curStart, $curEnd),
                'previous_label' => $this->formatShortRangeLabel($prevStart, $prevEnd),
                'gmv_scope_hint' => 'Week-to-date GMV',
            ];
        }

        if ($period === self::PERIOD_YEARLY) {
            $curStart = $asOf->copy()->startOfYear();
            $curEnd = $asOf->copy()->endOfDay();
            $prevStart = $curStart->copy()->subYear();
            $prevEnd = $curEnd->copy()->subYear();

            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $asOf->year.' YTD',
                'previous_label' => ($asOf->year - 1).' (same span)',
                'gmv_scope_hint' => 'YTD GMV',
            ];
        }

        if ($period === self::PERIOD_MONTHLY) {
            $curStart = $asOf->copy()->startOfMonth();
            $curEnd = $this->minDateTime($asOf->copy()->endOfDay(), $asOf->copy()->endOfMonth());
            $prevStart = $curStart->copy()->subMonth()->startOfMonth();
            $prevEnd = $curStart->copy()->subSecond();

            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $curStart->format('M Y'),
                'previous_label' => $prevStart->format('M Y'),
                'gmv_scope_hint' => 'MTD GMV',
            ];
        }

        throw new InvalidArgumentException('Invalid period type.');
    }

    private function formatShortRangeLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('M j, Y');
        }

        return $start->format('M j').' – '.$end->format('M j, Y');
    }

    private function formatCustomRangeLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('M j, Y');
        }
        if ($start->year === $end->year) {
            return $start->format('M j').' – '.$end->format('M j, Y');
        }

        return $start->format('M j, Y').' – '.$end->format('M j, Y');
    }

    /**
     * Aggregate all P&L metrics for a period using a single SQL GROUP BY on
     * the transaction_commissions ledger — no PHP cursor loop.
     *
     * platform_fee = ticketoc_commission_percent / 100 × (gross_amount − markup_amount − tax_and_fees)
     * This matches the original line-pricing formula (commission on net_selling only).
     * For rows created before the markup_amount / tax_and_fees columns were added,
     * those columns default to 0, so platform_fee degrades to the older
     * total_amount × rate calculation — still correct for those historical rows.
     *
     * @return array<string, float|int|null>
     */
    private function periodMetrics(Carbon $start, Carbon $end, float $platformDefault): array
    {
        // One GROUP BY query replacing the previous PHP cursor loop.
        $salesRows = TransactionCommission::query()
            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION'])
            ->whereBetween('date_paid', [$start, $end])
            ->selectRaw('payment_provider')
            ->selectRaw('payment_method')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_amount')
            ->selectRaw('COALESCE(SUM(markup_amount), 0) AS markup_amount')
            ->selectRaw('COALESCE(SUM(tax_and_fees), 0) AS tax_and_fees')
            ->selectRaw('COALESCE(SUM(payment_gateway_commission + payment_gateway_fixed_fee), 0) AS gateway_fees')
            ->selectRaw(
                'COALESCE(SUM(ticketoc_commission_percent / 100'
                .' * (gross_amount - markup_amount - tax_and_fees)), 0) AS platform_fee'
            )
            ->selectRaw('COALESCE(SUM(ticketoc_commission_percent), 0) AS commission_pct_sum')
            ->selectRaw('COUNT(*) AS txn_count')
            ->groupBy('payment_provider', 'payment_method')
            ->get();

        // Cross-month refund clawback: platform revenue that must be reversed.
        $clawbackResult = TransactionCommission::query()
            ->where('transaction_type', TransactionCommission::TYPE['REFUND'])
            ->whereBetween('date_paid', [$start, $end])
            ->whereNotNull('original_paid_at')
            ->whereRaw("DATE_FORMAT(date_paid, '%Y-%m') != DATE_FORMAT(original_paid_at, '%Y-%m')")
            ->selectRaw(
                'COALESCE(SUM('
                .'  ticketoc_commission_percent / 100 * (gross_amount - markup_amount - tax_and_fees)'
                .'  + markup_amount'
                .'), 0) AS clawback'
            )
            ->first();

        // Pivot the grouped rows into the scalar aggregates the rest of the
        // service expects. PayMongo method keys in the ledger use the dataset
        // convention (qrph, gcash, …); map them to the P&L bucket names.
        $grossSalesGmv = 0.0;
        $markupTotal = 0.0;
        $taxAndFees = 0.0;
        $platformFee = 0.0;
        $gatewayFees = 0.0;
        $gatewayFeesPaypal = 0.0;
        $commissionPctSum = 0.0;
        $txnCount = 0;

        $paymongoByMethod = ['gateway_fees_paymongo_unspecified' => 0.0];
        foreach (self::PAYMONGO_INCOME_STATEMENT_METHODS as $mk) {
            $paymongoByMethod[$this->paymongoGatewayField($mk)] = 0.0;
        }

        foreach ($salesRows as $row) {
            $rowGateway = (float) $row->gateway_fees;
            $grossSalesGmv += (float) $row->gross_amount;
            $markupTotal += (float) $row->markup_amount;
            $taxAndFees += (float) $row->tax_and_fees;
            $platformFee += (float) $row->platform_fee;
            $gatewayFees += $rowGateway;
            $commissionPctSum += (float) $row->commission_pct_sum;
            $txnCount += (int) $row->txn_count;

            $provider = strtolower((string) ($row->payment_provider ?? ''));
            if ($provider === 'paypal') {
                $gatewayFeesPaypal += $rowGateway;
            } elseif ($provider === 'paymongo') {
                $pnlBucket = $this->ledgerMethodToPnlBucket((string) ($row->payment_method ?? ''));
                $field = $pnlBucket !== null ? $this->paymongoGatewayField($pnlBucket) : null;
                if ($field !== null) {
                    $paymongoByMethod[$field] += $rowGateway;
                } else {
                    $paymongoByMethod['gateway_fees_paymongo_unspecified'] += $rowGateway;
                }
            }
        }

        $commissionRevenue = round($platformFee + $markupTotal, 2);
        $refunds = $this->sumRefunds($start, $end);
        $chargebacks = 0.0;
        $netGmv = round($grossSalesGmv - $refunds - $chargebacks, 2);

        $clawback = round((float) ($clawbackResult?->clawback ?? 0.0), 2);
        $contributionMargin = round($commissionRevenue - $gatewayFees - $clawback, 2);

        $totalNetSelling = $grossSalesGmv - $markupTotal - $taxAndFees;
        $weightedAvgCommissionPct = $totalNetSelling > 0
            ? round($platformFee / $totalNetSelling * 100.0, 2)
            : round($platformDefault, 2);

        $row = [
            'gross_sales_gmv' => round($grossSalesGmv, 2),
            'tax_and_fees' => round($taxAndFees, 2),
            'refunds' => round($refunds, 2),
            'chargebacks' => round($chargebacks, 2),
            /** @deprecated Kept for API compatibility; always zero */
            'cancellations' => 0.0,
            'net_gmv' => round($netGmv, 2),
            'commission_revenue' => $commissionRevenue,
            'gateway_fees' => round($gatewayFees, 2),
            'gateway_fees_paypal' => round($gatewayFeesPaypal, 2),
            'variable_refund_cancel_commission' => $clawback,
            'contribution_margin' => $contributionMargin,
            'weighted_avg_commission_pct' => $weightedAvgCommissionPct,
        ];

        foreach (self::PAYMONGO_INCOME_STATEMENT_METHODS as $methodKey) {
            $field = $this->paymongoGatewayField($methodKey);
            $row[$field] = round($paymongoByMethod[$field] ?? 0.0, 2);
        }
        $row['gateway_fees_paymongo_unspecified'] = round($paymongoByMethod['gateway_fees_paymongo_unspecified'], 2);

        return $row;
    }

    /**
     * Maps transaction_commissions.payment_method (dataset key convention: qrph,
     * gcash, …) to the P&L income-statement bucket name (qr_ph, gcash, …).
     */
    private function ledgerMethodToPnlBucket(string $method): ?string
    {
        if ($method === '') {
            return null;
        }

        return match ($method) {
            'qrph' => 'qr_ph',
            'card', 'gcash', 'grab_pay', 'shopee_pay',
            'billease', 'paymaya', 'dob', 'brankas' => $method,
            default => null,
        };
    }

    private function sumRefunds(Carbon $start, Carbon $end): float
    {
        return (float) Transaction::query()
            ->where('status', Transaction::STATUS['REFUNDED'])
            ->whereBetween('updated_at', [$start, $end])
            ->sum('total_amount');
    }

    private function paymongoGatewayField(string $methodKey): string
    {
        return 'gateway_fees_paymongo_'.$methodKey;
    }

    private function paymongoIncomeRowLabel(string $methodKey): string
    {
        return match ($methodKey) {
            'qr_ph' => 'QRPH',
            'card' => 'Card',
            'gcash' => 'GCash',
            'grab_pay' => 'Grab Pay',
            'shopee_pay' => 'Shopee Pay',
            'billease' => 'Billease',
            'paymaya' => 'PayMaya',
            'dob' => 'DOB',
            'brankas' => 'Brankas',
            default => $methodKey,
        };
    }

    /**
     * Unweighted mean of each merchant’s effective commission % (org override or platform default).
     */
    private function averageAllMerchantsEffectiveCommissionPercent(float $platformDefault): float
    {
        $orgs = Organization::query()->get(['uuid', 'commission_percentage']);
        if ($orgs->isEmpty()) {
            return round($platformDefault, 2);
        }

        $total = 0.0;
        foreach ($orgs as $org) {
            $total += ($org !== null && $org->commission_percentage !== null) ? (float) $org->commission_percentage : $platformDefault;
        }

        return round($total / $orgs->count(), 2);
    }

    /**
     * @param  array<string, mixed>  $cur
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $ytd
     * @return list<array<string, mixed>>
     */
    private function incomeStatementRows(array $cur, array $prev, array $ytd): array
    {
        $rows = [
            $this->row('gmv', 'Gross sales (GMV)', false, 'standard', $cur, $prev, $ytd, 'gross_sales_gmv'),
            $this->row('refunds', 'refunds', true, 'standard', $cur, $prev, $ytd, 'refunds', true),
            $this->row('chargebacks', 'chargebacks', true, 'standard', $cur, $prev, $ytd, 'chargebacks', true),
            $this->row('net_gmv', 'Net GMV', false, 'summary', $cur, $prev, $ytd, 'net_gmv'),
        ];

        $rows[] = [
            'key' => 'commission',
            'label' => 'Net revenue (platform fee + markup)',
            'less' => false,
            'variant' => 'commission',
            'current_month' => $cur['commission_revenue'],
            'previous_month' => $prev['commission_revenue'],
            'mom_pct' => $this->percentChange($prev['commission_revenue'], $cur['commission_revenue']),
            'ytd' => $ytd['commission_revenue'],
            'pct_of_gmv' => $this->pctOfGmvSigned($cur['commission_revenue'], $cur['gross_sales_gmv']),
        ];

        $rows[] = $this->row(
            'gateway_paypal',
            'PayPal',
            true,
            'standard',
            $cur,
            $prev,
            $ytd,
            'gateway_fees_paypal',
            true
        );

        foreach (self::PAYMONGO_INCOME_STATEMENT_METHODS as $methodKey) {
            $field = $this->paymongoGatewayField($methodKey);
            $rows[] = $this->row(
                'gateway_pm_'.$methodKey,
                $this->paymongoIncomeRowLabel($methodKey),
                true,
                'standard',
                $cur,
                $prev,
                $ytd,
                $field,
                true
            );
        }

        $rows[] = $this->row(
            'gateway_pm_unspecified',
            'PayMongo (unspecified)',
            true,
            'standard',
            $cur,
            $prev,
            $ytd,
            'gateway_fees_paymongo_unspecified',
            true
        );

        $rows[] = [
            'key' => 'margin',
            'label' => 'Contribution margin',
            'less' => false,
            'variant' => 'margin',
            'current_month' => $cur['contribution_margin'],
            'previous_month' => $prev['contribution_margin'],
            'mom_pct' => $this->percentChange($prev['contribution_margin'], $cur['contribution_margin']),
            'ytd' => $ytd['contribution_margin'],
            'pct_of_gmv' => $this->pctOfGmvSigned($cur['contribution_margin'], $cur['gross_sales_gmv']),
        ];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $cur
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $ytd
     * @return array<string, mixed>
     */
    private function row(
        string $key,
        string $label,
        bool $less,
        string $variant,
        array $cur,
        array $prev,
        array $ytd,
        string $field,
        bool $deduction = false
    ): array {
        $c = (float) $cur[$field];
        $p = (float) $prev[$field];
        $mom = $this->percentChange($p, $c);
        $sign = $deduction && $c > 0 ? -1.0 : 1.0;

        return [
            'key' => $key,
            'label' => $label,
            'less' => $less,
            'variant' => $variant,
            'current_month' => $c,
            'previous_month' => $p,
            'mom_pct' => $mom,
            'ytd' => (float) $ytd[$field],
            'pct_of_gmv' => $this->pctOfGmvSigned($sign * $c, (float) $cur['gross_sales_gmv']),
        ];
    }

    private function pctOfGmvSigned(float $value, float $gmv): float
    {
        if ($gmv <= 0.0) {
            return 0.0;
        }

        return round($value / $gmv * 100.0, 1);
    }

    private function percentChange(float $previous, float $current): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round(($current - $previous) / $previous * 100.0, 1);
    }

    private function minDateTime(Carbon $a, Carbon $b): Carbon
    {
        return $a->lte($b) ? $a : $b;
    }
}
