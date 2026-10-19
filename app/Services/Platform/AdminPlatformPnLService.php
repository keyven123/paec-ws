<?php

namespace App\Services\Platform;

use App\Constants\GeneralConstants;
use App\Http\Repositories\PaymentGatewayRateSettingRepository;
use App\Models\Dataset;
use App\Models\Organization;
use App\Models\PaymentGateway;
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

    public function __construct(
        protected ?PaymentGatewayRateSettingRepository $rateSettingRepository = null
    ) {
        $this->rateSettingRepository ??= app(PaymentGatewayRateSettingRepository::class);
    }

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
        $momRefunds = $this->percentChange($prev['refunds'], $cur['refunds']);
        $momChargebacks = $this->percentChange($prev['chargebacks'], $cur['chargebacks']);
        $momNetSales = $this->percentChange($prev['net_sales'], $cur['net_sales']);
        $momNetGmv = $this->percentChange($prev['net_gmv'], $cur['net_gmv']);

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
                'refunds' => round($cur['refunds'], 2),
                'chargebacks' => round($cur['chargebacks'], 2),
                'gateway_fees' => round($cur['gateway_fees_visible'] ?? $cur['gateway_fees'], 2),
                'net_gmv' => round($cur['net_gmv'], 2),
                'net_sales' => round($cur['net_sales'], 2),
                'mom_gross_sales_gmv_pct' => $momGmv,
                'mom_refunds_pct' => $momRefunds,
                'mom_chargebacks_pct' => $momChargebacks,
                'mom_gateway_fees_pct' => $this->percentChange(
                    (float) ($prev['gateway_fees_visible'] ?? $prev['gateway_fees']),
                    (float) ($cur['gateway_fees_visible'] ?? $cur['gateway_fees'])
                ),
                'mom_net_sales_pct' => $momNetSales,
                'mom_net_gmv_pct' => $momNetGmv,
                // Legacy commission fields kept for older clients; UI no longer displays them.
                'net_revenue_commission' => round($cur['commission_revenue'], 2),
                'tax_and_fees' => round($cur['tax_and_fees'], 2),
                'effective_take_rate_avg_merchant_pct' => round($avgMerchantPctCur, 2),
                'effective_take_rate_on_gmv_pct' => $cur['gross_sales_gmv'] > 0
                    ? round($cur['commission_revenue'] / $cur['gross_sales_gmv'] * 100.0, 2)
                    : 0.0,
                'contribution_margin' => round($cur['contribution_margin'], 2),
                'contribution_margin_pct_of_revenue' => $cur['commission_revenue'] > 0
                    ? round($cur['contribution_margin'] / $cur['commission_revenue'] * 100.0, 1)
                    : 0.0,
                'mom_net_revenue_commission_pct' => $this->percentChange(
                    $prev['commission_revenue'],
                    $cur['commission_revenue']
                ),
                'mom_tax_and_fees_pct' => $this->percentChange($prev['tax_and_fees'], $cur['tax_and_fees']),
                'mom_contribution_margin_pct' => $this->percentChange(
                    $prev['contribution_margin'],
                    $cur['contribution_margin']
                ),
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
            $nDays = (int) $curStart->diffInDays($curEnd) + 1;
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

        /** @var array<string, array{label: string, total: float, channels: array<string, array{label: string, amount: float}>}> $gatewayBreakdown */
        $gatewayBreakdown = [];

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
            $method = strtolower(trim((string) ($row->payment_method ?? '')));
            if ($provider === '' || $provider === 'free') {
                if ($rowGateway > 0) {
                    $this->accumulateGatewayBreakdown(
                        $gatewayBreakdown,
                        'other',
                        'Other',
                        $method !== '' ? $method : 'unspecified',
                        $this->channelLabelForProvider('other', $method !== '' ? $method : 'unspecified'),
                        $rowGateway
                    );
                }
            } else {
                $this->accumulateGatewayBreakdown(
                    $gatewayBreakdown,
                    $provider,
                    $this->providerLabel($provider),
                    $method !== '' ? $method : 'default',
                    $this->channelLabelForProvider($provider, $method !== '' ? $method : 'default'),
                    $rowGateway
                );
            }

            if ($provider === 'paypal') {
                $gatewayFeesPaypal += $rowGateway;
            } elseif ($provider === 'paymongo') {
                $pnlBucket = $this->ledgerMethodToPnlBucket($method);
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
        $gatewayFeesRounded = round($gatewayFees, 2);
        // Pre-gateway net (gross − refunds − chargebacks).
        $netGmv = round($grossSalesGmv - $refunds - $chargebacks, 2);
        $gatewayBreakdownNormalized = $this->normalizeGatewayBreakdown($gatewayBreakdown);
        // Only channels checked in Finance Settings count toward Net sales.
        $visibleGatewayFees = $this->sumVisibleGatewayFees($gatewayBreakdownNormalized);
        $netSales = round($netGmv - $visibleGatewayFees, 2);

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
            'net_sales' => $netSales,
            'commission_revenue' => $commissionRevenue,
            'gateway_fees' => $gatewayFeesRounded,
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
        $row['gateway_breakdown'] = $gatewayBreakdownNormalized;
        $row['gateway_fees_visible'] = $visibleGatewayFees;

        return $row;
    }

    /**
     * @param  list<array{key: string, label: string, total: float, channels: list<array{key: string, label: string, amount: float}>}>  $breakdown
     */
    private function sumVisibleGatewayFees(array $breakdown): float
    {
        $total = 0.0;
        foreach ($breakdown as $provider) {
            $providerKey = (string) $provider['key'];
            foreach ($provider['channels'] as $channel) {
                $channelKey = (string) $channel['key'];
                if ($this->rateSettingRepository->isChannelVisibleInSales($providerKey, $channelKey)) {
                    $total += (float) $channel['amount'];
                }
            }
        }

        return round($total, 2);
    }

    /**
     * @param  array<string, array{label: string, total: float, channels: array<string, array{label: string, amount: float}>}>  $breakdown
     */
    private function accumulateGatewayBreakdown(
        array &$breakdown,
        string $providerKey,
        string $providerLabel,
        string $channelKey,
        string $channelLabel,
        float $amount
    ): void {
        if (! isset($breakdown[$providerKey])) {
            $breakdown[$providerKey] = [
                'label' => $providerLabel,
                'total' => 0.0,
                'channels' => [],
            ];
        }
        $breakdown[$providerKey]['total'] += $amount;
        if (! isset($breakdown[$providerKey]['channels'][$channelKey])) {
            $breakdown[$providerKey]['channels'][$channelKey] = [
                'label' => $channelLabel,
                'amount' => 0.0,
            ];
        }
        $breakdown[$providerKey]['channels'][$channelKey]['amount'] += $amount;
    }

    /**
     * @param  array<string, array{label: string, total: float, channels: array<string, array{label: string, amount: float}>}>  $breakdown
     * @return list<array{key: string, label: string, total: float, channels: list<array{key: string, label: string, amount: float}>}>
     */
    private function normalizeGatewayBreakdown(array $breakdown): array
    {
        $order = ['paynamics', 'paymongo', 'paypal', 'other'];
        uksort($breakdown, function (string $a, string $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = $ia === false ? 99 : $ia;
            $ib = $ib === false ? 99 : $ib;
            if ($ia === $ib) {
                return strcmp($a, $b);
            }

            return $ia <=> $ib;
        });

        $normalized = [];
        foreach ($breakdown as $providerKey => $provider) {
            $channels = [];
            foreach ($provider['channels'] as $channelKey => $channel) {
                $channels[] = [
                    'key' => $channelKey,
                    'label' => $channel['label'],
                    'amount' => round($channel['amount'], 2),
                ];
            }
            usort($channels, fn ($a, $b) => strcmp($a['label'], $b['label']));
            $normalized[] = [
                'key' => $providerKey,
                'label' => $provider['label'],
                'total' => round($provider['total'], 2),
                'channels' => $channels,
            ];
        }

        return $normalized;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'paynamics' => 'Paynamics',
            'paymongo' => 'PayMongo',
            'paypal' => 'PayPal',
            default => ucfirst($provider),
        };
    }

    private function channelLabelForProvider(string $provider, string $method): string
    {
        if ($provider === 'paypal' || $method === 'default') {
            return $provider === 'paypal' ? 'PayPal' : 'General';
        }

        if ($provider === 'paymongo') {
            $bucket = $this->ledgerMethodToPnlBucket($method) ?? $method;

            return $this->paymongoIncomeRowLabel($bucket === 'qrph' ? 'qr_ph' : $bucket);
        }

        if ($provider === 'paynamics') {
            return match ($method) {
                'card' => 'Credit / Debit Card',
                'gcash' => 'GCash',
                'maya' => 'Maya (PayMaya)',
                'grabpay' => 'GrabPay',
                'shopeepay' => 'ShopeePay',
                'bdo' => 'BDO Online Banking',
                'bpi' => 'BPI Direct Debit',
                'landbank' => 'Landbank',
                'unionbank' => 'UnionBank Direct Debit',
                'billease' => 'BillEase',
                'unspecified', '' => 'Unspecified',
                default => strtoupper($method),
            };
        }

        return $method !== '' ? strtoupper($method) : 'Unspecified';
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
        // Gross → refunds / chargebacks → checked channels under gateway names → Net sales.
        $rows = [
            $this->row('gmv', 'Gross sales', false, 'standard', $cur, $prev, $ytd, 'gross_sales_gmv'),
            $this->row('refunds', 'Refunds', true, 'standard', $cur, $prev, $ytd, 'refunds', true),
            $this->row('chargebacks', 'Chargebacks', true, 'standard', $cur, $prev, $ytd, 'chargebacks', true),
        ];

        $curBreakdown = is_array($cur['gateway_breakdown'] ?? null) ? $cur['gateway_breakdown'] : [];
        $prevBreakdown = is_array($prev['gateway_breakdown'] ?? null) ? $prev['gateway_breakdown'] : [];
        $ytdBreakdown = is_array($ytd['gateway_breakdown'] ?? null) ? $ytd['gateway_breakdown'] : [];

        $prevByProvider = [];
        foreach ($prevBreakdown as $provider) {
            $prevByProvider[$provider['key']] = $provider;
        }
        $ytdByProvider = [];
        foreach ($ytdBreakdown as $provider) {
            $ytdByProvider[$provider['key']] = $provider;
        }

        $amountLookup = function (array $breakdown, string $providerKey, string $channelKey): float {
            foreach ($breakdown as $provider) {
                if ((string) $provider['key'] !== $providerKey) {
                    continue;
                }
                foreach ($provider['channels'] ?? [] as $channel) {
                    if ((string) $channel['key'] === $channelKey) {
                        return (float) ($channel['amount'] ?? 0);
                    }
                }
            }

            return 0.0;
        };

        foreach ($this->visibleSalesChannelTree() as $gateway) {
            $providerKey = (string) $gateway['provider'];
            $gatewayUuid = (string) $gateway['uuid'];
            $visibleChannels = $gateway['channels'];
            if ($visibleChannels === []) {
                continue;
            }

            $curProviderTotal = 0.0;
            $prevProviderTotal = 0.0;
            $ytdProviderTotal = 0.0;
            foreach ($visibleChannels as $channel) {
                $channelKey = (string) $channel['key'];
                $curProviderTotal += $amountLookup($curBreakdown, $providerKey, $channelKey);
                $prevProviderTotal += $amountLookup($prevBreakdown, $providerKey, $channelKey);
                $ytdProviderTotal += $amountLookup($ytdBreakdown, $providerKey, $channelKey);
            }

            $rows[] = $this->literalRow(
                'gateway_provider_'.$gatewayUuid,
                (string) $gateway['name'],
                true,
                'gateway_provider',
                $curProviderTotal,
                $prevProviderTotal,
                $ytdProviderTotal,
                (float) $cur['gross_sales_gmv'],
                1
            );

            foreach ($visibleChannels as $channel) {
                $channelKey = (string) $channel['key'];
                $rows[] = $this->literalRow(
                    'gateway_channel_'.$gatewayUuid.'_'.$channelKey,
                    (string) $channel['label'],
                    true,
                    'gateway_channel',
                    $amountLookup($curBreakdown, $providerKey, $channelKey),
                    $amountLookup($prevBreakdown, $providerKey, $channelKey),
                    $amountLookup($ytdBreakdown, $providerKey, $channelKey),
                    (float) $cur['gross_sales_gmv'],
                    2
                );
            }
        }

        $rows[] = $this->row('net_sales', 'Net sales', false, 'summary', $cur, $prev, $ytd, 'net_sales');

        return $rows;
    }

    /**
     * Active gateways with only channels checked for Sales summary.
     *
     * @return list<array{uuid: string, name: string, provider: string, channels: list<array{key: string, label: string}>}>
     */
    private function visibleSalesChannelTree(): array
    {
        $gateways = PaymentGateway::query()
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->orderBy('provider')
            ->orderBy('name')
            ->get(['uuid', 'name', 'provider', 'payment_channels']);

        $paynamicsCatalog = collect($this->rateSettingRepository->paynamicsChannelCatalog())
            ->keyBy('key');
        $paymongoCatalog = collect($this->rateSettingRepository->paymongoChannelCatalog())
            ->keyBy('key');

        $tree = [];
        foreach ($gateways as $gateway) {
            $provider = (string) $gateway->provider;
            $channels = [];

            if ($provider === 'paynamics') {
                foreach ($gateway->enabledPaynamicsChannels() as $channelKey) {
                    if (! $this->rateSettingRepository->isChannelVisibleInSales('paynamics', $channelKey)) {
                        continue;
                    }
                    $channels[] = [
                        'key' => $channelKey,
                        'label' => (string) ($paynamicsCatalog[$channelKey]['label'] ?? $this->channelLabelForProvider('paynamics', $channelKey)),
                    ];
                }
            } elseif ($provider === 'paymongo') {
                foreach ($paymongoCatalog as $channelKey => $meta) {
                    if (! $this->rateSettingRepository->isChannelVisibleInSales('paymongo', (string) $channelKey)) {
                        continue;
                    }
                    $channels[] = [
                        'key' => (string) $channelKey,
                        'label' => (string) $meta['label'],
                    ];
                }
            } elseif ($provider === 'paypal') {
                if ($this->rateSettingRepository->isChannelVisibleInSales('paypal', 'default')) {
                    $channels[] = [
                        'key' => 'default',
                        'label' => 'PayPal',
                    ];
                }
            }

            if ($channels === []) {
                continue;
            }

            $tree[] = [
                'uuid' => (string) $gateway->uuid,
                'name' => (string) $gateway->name,
                'provider' => $provider,
                'channels' => $channels,
            ];
        }

        return $tree;
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
        bool $deduction = false,
        int $indent = 0
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
            'indent' => $indent,
            'current_month' => $c,
            'previous_month' => $p,
            'mom_pct' => $mom,
            'ytd' => (float) ($ytd[$field] ?? 0),
            'pct_of_gmv' => $this->pctOfGmvSigned($sign * $c, (float) $cur['gross_sales_gmv']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function literalRow(
        string $key,
        string $label,
        bool $less,
        string $variant,
        float $current,
        float $previous,
        float $ytd,
        float $gross,
        int $indent
    ): array {
        $sign = $less && $current > 0 ? -1.0 : 1.0;

        return [
            'key' => $key,
            'label' => $label,
            'less' => $less,
            'variant' => $variant,
            'indent' => $indent,
            'current_month' => $current,
            'previous_month' => $previous,
            'mom_pct' => $this->percentChange($previous, $current),
            'ytd' => $ytd,
            'pct_of_gmv' => $this->pctOfGmvSigned($sign * $current, $gross),
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
