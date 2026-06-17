<?php

namespace App\Services\Organizer;

use App\Models\Organization;
use App\Models\TransactionCommission;
use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\Platform\AdminPlatformPnLService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class OrganizerPnLService
{
    public function __construct(
        protected OrganizerAccountingReportService $reportService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(
        string $organizationUuid,
        ?Carbon $asOf = null,
        string $period = AdminPlatformPnLService::PERIOD_MONTHLY,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null,
        ?string $eventUuid = null,
    ): array {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $asOf = ($asOf ?? Carbon::now($tz))->copy()->timezone($tz);
        $endOfToday = $asOf->copy()->endOfDay();

        $windows = $this->resolvePeriodWindows($period, $asOf, $customStart, $customEnd);
        $curStart = $windows['cur_start'];
        $curEnd = $windows['cur_end'];
        $prevStart = $windows['prev_start'];
        $prevEnd = $windows['prev_end'];

        $ytdStart = $asOf->copy()->startOfYear();
        $ytdEnd = $endOfToday;

        $cur = $this->periodMetrics($organizationUuid, $curStart, $curEnd, $eventUuid);
        $prev = $this->periodMetrics($organizationUuid, $prevStart, $prevEnd, $eventUuid);
        $ytd = $this->periodMetrics($organizationUuid, $ytdStart, $ytdEnd, $eventUuid);

        $summary = $this->reportService->summary($organizationUuid, $eventUuid);
        $org = Organization::query()->where('uuid', $organizationUuid)->first();
        $commissionRate = $summary['effective_commission_percentage'] ?? null;

        $momGmv = $this->percentChange($prev['gross_sales_gmv'], $cur['gross_sales_gmv']);
        $momNet = $this->percentChange($prev['net_merchant_revenue'], $cur['net_merchant_revenue']);
        $momCommission = $this->percentChange($prev['platform_commission'], $cur['platform_commission']);

        $effectiveCommissionOnGmv = $cur['gross_sales_gmv'] > 0
            ? round($cur['platform_commission'] / $cur['gross_sales_gmv'] * 100.0, 2)
            : 0.0;

        $netPctOfGmv = $cur['gross_sales_gmv'] > 0
            ? round($cur['net_merchant_revenue'] / $cur['gross_sales_gmv'] * 100.0, 1)
            : 0.0;

        return [
            'as_of' => $endOfToday->toIso8601String(),
            'timezone' => $tz,
            'organization_uuid' => $organizationUuid,
            'event_uuid' => $eventUuid,
            'effective_commission_percentage' => $commissionRate,
            'period' => [
                'type' => $period,
                'current_label' => $windows['current_label'],
                'previous_label' => $windows['previous_label'],
                'gmv_scope_hint' => $windows['gmv_scope_hint'],
            ],
            'kpi' => [
                'gross_sales_gmv' => round($cur['gross_sales_gmv'], 2),
                'net_merchant_revenue' => round($cur['net_merchant_revenue'], 2),
                'platform_commission' => round($cur['platform_commission'], 2),
                'tax_and_fees' => round($cur['tax_and_fees'], 2),
                'effective_commission_on_gmv_pct' => $effectiveCommissionOnGmv,
                'net_merchant_revenue_pct_of_gmv' => $netPctOfGmv,
                'available_for_payout' => round((float) ($summary['available'] ?? 0), 2),
                'pending_remittance' => round((float) ($summary['pending'] ?? 0), 2),
                'total_cashout' => round((float) ($summary['total_cashout'] ?? 0), 2),
                'mom_gross_sales_gmv_pct' => $momGmv,
                'mom_net_merchant_revenue_pct' => $momNet,
                'mom_platform_commission_pct' => $momCommission,
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
     * @return array<string, float>
     */
    private function periodMetrics(
        string $organizationUuid,
        Carbon $start,
        Carbon $end,
        ?string $eventUuid = null,
    ): array {
        $query = TransactionCommission::query()
            ->where('organization_uuid', $organizationUuid)
            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION'])
            ->whereBetween('date_paid', [$start, $end])
            ->when($eventUuid !== null, fn ($q) => $q->where('event_uuid', $eventUuid));

        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_sales_gmv')
            ->selectRaw('COALESCE(SUM(ticketoc_commission), 0) AS platform_commission')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net_merchant_revenue')
            ->selectRaw('COALESCE(SUM(tax_and_fees), 0) AS tax_and_fees')
            ->first();

        $refunds = (float) TransactionCommission::query()
            ->where('organization_uuid', $organizationUuid)
            ->where('transaction_type', TransactionCommission::TYPE['REFUND'])
            ->whereBetween('date_paid', [$start, $end])
            ->when($eventUuid !== null, fn ($q) => $q->where('event_uuid', $eventUuid))
            ->sum('gross_amount');

        $refunds = abs($refunds);

        $gross = (float) ($totals->gross_sales_gmv ?? 0);
        $netGmv = max(0.0, $gross - $refunds);

        return [
            'gross_sales_gmv' => round($gross, 2),
            'refunds' => round($refunds, 2),
            'net_gmv' => round($netGmv, 2),
            'platform_commission' => round((float) ($totals->platform_commission ?? 0), 2),
            'net_merchant_revenue' => round((float) ($totals->net_merchant_revenue ?? 0), 2),
            'tax_and_fees' => round((float) ($totals->tax_and_fees ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, float>  $cur
     * @param  array<string, float>  $prev
     * @param  array<string, float>  $ytd
     * @return list<array<string, mixed>>
     */
    private function incomeStatementRows(array $cur, array $prev, array $ytd): array
    {
        return [
            $this->row('gmv', 'Gross sales (GMV)', false, 'standard', $cur, $prev, $ytd, 'gross_sales_gmv'),
            $this->row('refunds', 'Refunds', true, 'standard', $cur, $prev, $ytd, 'refunds', true),
            $this->row('net_gmv', 'Net GMV', false, 'summary', $cur, $prev, $ytd, 'net_gmv'),
            $this->row(
                'platform_commission',
                'Platform commission',
                true,
                'commission',
                $cur,
                $prev,
                $ytd,
                'platform_commission',
                true,
            ),
            [
                'key' => 'net_merchant_revenue',
                'label' => 'Net merchant revenue',
                'less' => false,
                'variant' => 'margin',
                'current_month' => $cur['net_merchant_revenue'],
                'previous_month' => $prev['net_merchant_revenue'],
                'mom_pct' => $this->percentChange($prev['net_merchant_revenue'], $cur['net_merchant_revenue']),
                'ytd' => $ytd['net_merchant_revenue'],
                'pct_of_gmv' => $this->pctOfGmvSigned($cur['net_merchant_revenue'], $cur['gross_sales_gmv']),
            ],
        ];
    }

    /**
     * @param  array<string, float>  $cur
     * @param  array<string, float>  $prev
     * @param  array<string, float>  $ytd
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
    ): array {
        $c = (float) $cur[$field];
        $p = (float) $prev[$field];
        $sign = $deduction && $c > 0 ? -1.0 : 1.0;

        return [
            'key' => $key,
            'label' => $label,
            'less' => $less,
            'variant' => $variant,
            'current_month' => $c,
            'previous_month' => $p,
            'mom_pct' => $this->percentChange($p, $c),
            'ytd' => (float) $ytd[$field],
            'pct_of_gmv' => $this->pctOfGmvSigned($sign * $c, (float) $cur['gross_sales_gmv']),
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
        ?Carbon $customEnd,
    ): array {
        $period = strtolower($period);

        if ($period === AdminPlatformPnLService::PERIOD_CUSTOM) {
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

        if ($period === AdminPlatformPnLService::PERIOD_DAILY) {
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

        if ($period === AdminPlatformPnLService::PERIOD_WEEKLY) {
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

        if ($period === AdminPlatformPnLService::PERIOD_YEARLY) {
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

        if ($period === AdminPlatformPnLService::PERIOD_MONTHLY) {
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
