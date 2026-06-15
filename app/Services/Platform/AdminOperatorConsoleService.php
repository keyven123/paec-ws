<?php

namespace App\Services\Platform;

use App\Models\Dataset;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\MerchantPayoutRequest;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class AdminOperatorConsoleService
{
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

    public function build(
        ?Carbon $asOf = null,
        string $period = self::PERIOD_DAILY,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null
    ): array
    {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $asOf = ($asOf ?? Carbon::now($tz))->copy()->timezone($tz);
        $todayStart = $asOf->copy()->startOfDay();
        $todayEnd = $asOf->copy()->endOfDay();
        $windows = $this->resolvePeriodWindows($period, $asOf, $customStart, $customEnd);

        $kpi = $this->kpiData(
            $windows['cur_start'],
            $windows['cur_end'],
            $windows['prev_start'],
            $windows['prev_end']
        );
        $float = $this->floatHeldData($todayStart, $tz);

        return [
            'as_of' => $todayEnd->toIso8601String(),
            'timezone' => $tz,
            'currency' => 'PHP',
            'period' => [
                'type' => strtolower($period),
                'current_label' => $windows['current_label'],
                'previous_label' => $windows['previous_label'],
                'current_range' => [
                    'start' => $windows['cur_start']->toIso8601String(),
                    'end' => $windows['cur_end']->toIso8601String(),
                ],
                'previous_range' => [
                    'start' => $windows['prev_start']->toIso8601String(),
                    'end' => $windows['prev_end']->toIso8601String(),
                ],
            ],
            'kpi' => $kpi,
            'float_held' => $float,
            'pending_payouts' => $this->pendingPayoutsSummary(),
            'remittances' => $this->remittancesSummary(),
            'approved_payout_requests' => $this->payoutRequestsSummary(
                MerchantPayoutRequest::STATUS_APPROVED
            ),
            'declined_payout_requests' => $this->payoutRequestsSummary(
                MerchantPayoutRequest::STATUS_DECLINED
            ),
            'organizers' => $this->organizersForRemittance(),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private function kpiData(Carbon $periodStart, Carbon $periodEnd, Carbon $prevStart, Carbon $prevEnd): array
    {
        $todayTxQuery = Transaction::query()
            ->with(['transactionOrders', 'organization'])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('status', '!=', Transaction::STATUS['REFUNDED'])
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$periodStart])
            ->whereRaw('COALESCE(paid_at, created_at) <= ?', [$periodEnd]);

        $ticketsSoldToday = 0;
        $grossToday = 0.0;
        $netRevenueToday = 0.0;
        $events = [];
        $orgRateCache = [];
        $platformDefault = Dataset::merchantCommissionPercent();

        foreach ($todayTxQuery->cursor() as $tx) {
            /** @var Transaction $tx */
            $grossToday += (float) $tx->total_amount;
            if ($tx->event_uuid) {
                $events[$tx->event_uuid] = true;
            }

            $rate = $this->effectiveRateForTransaction($tx, $orgRateCache, $platformDefault);
            $lineSum = 0.0;
            foreach ($tx->transactionOrders as $order) {
                $line = (float) $order->total_amount;
                $qty = (int) $order->quantity;
                $lineSum += $line;
                $ticketsSoldToday += max(0, $qty);
                $netRevenueToday += round($line * ($rate / 100.0), 2);
            }
            if ($lineSum <= 0.0) {
                $gross = (float) $tx->total_amount;
                $netRevenueToday += round($gross * ($rate / 100.0), 2);
            }
        }

        $currentDays = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $currentDaily = round($ticketsSoldToday / $currentDays, 2);
        $previousDaily = $this->averageTicketsForWindow($prevStart, $prevEnd);
        $ticketsVsAvgPct = $previousDaily > 0
            ? round((($currentDaily - $previousDaily) / $previousDaily) * 100.0, 1)
            : null;

        $effectiveTakeRate = $grossToday > 0 ? round(($netRevenueToday / $grossToday) * 100.0, 2) : 0.0;

        $refundsPendingCount = (int) Transaction::query()
            ->where(function ($q): void {
                $q->where('status', Transaction::STATUS['CANCELLED'])
                    ->orWhere('status', Transaction::STATUS['REFUNDED']);
            })
            ->whereBetween('updated_at', [$periodStart, $periodEnd])
            ->count();

        $refundsPendingAmount = (float) Transaction::query()
            ->where(function ($q): void {
                $q->where('status', Transaction::STATUS['CANCELLED'])
                    ->orWhere('status', Transaction::STATUS['REFUNDED']);
            })
            ->whereBetween('updated_at', [$periodStart, $periodEnd])
            ->sum('total_amount');

        return [
            'tickets_sold_today' => $ticketsSoldToday,
            'tickets_vs_avg_pct' => $ticketsVsAvgPct,
            'gross_today' => round($grossToday, 2),
            'gross_today_event_count' => count($events),
            'net_revenue_today' => round($netRevenueToday, 2),
            'effective_take_rate_today' => $effectiveTakeRate,
            'refunds_pending_count' => $refundsPendingCount,
            'refunds_pending_amount' => round($refundsPendingAmount, 2),
        ];
    }

    private function averageTicketsForWindow(Carbon $windowStart, Carbon $windowEnd): float
    {
        $txIds = Transaction::query()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('status', '!=', Transaction::STATUS['REFUNDED'])
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$windowStart])
            ->whereRaw('COALESCE(paid_at, created_at) <= ?', [$windowEnd])
            ->pluck('uuid');

        if ($txIds->isEmpty()) {
            return 0.0;
        }

        $qty = (int) TransactionOrder::query()
            ->whereIn('transaction_uuid', $txIds)
            ->sum('quantity');
        $days = max(1, $windowStart->diffInDays($windowEnd) + 1);

        return round($qty / $days, 2);
    }

    /**
     * @return array{
     *   cur_start: Carbon, cur_end: Carbon, prev_start: Carbon, prev_end: Carbon,
     *   current_label: string, previous_label: string
     * }
     */
    private function resolvePeriodWindows(string $period, Carbon $asOf, ?Carbon $customStart, ?Carbon $customEnd): array
    {
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
            $days = max(1, $curStart->diffInDays($curEnd) + 1);
            $prevEnd = $curStart->copy()->subDay()->endOfDay();
            $prevStart = $prevEnd->copy()->subDays($days - 1)->startOfDay();
            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $this->formatRange($curStart, $curEnd),
                'previous_label' => $this->formatRange($prevStart, $prevEnd),
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
                'current_label' => $this->formatRange($curStart, $curEnd),
                'previous_label' => $this->formatRange($prevStart, $prevEnd),
            ];
        }

        if ($period === self::PERIOD_MONTHLY) {
            $curStart = $asOf->copy()->startOfMonth();
            $curEnd = $asOf->copy()->endOfDay();
            $prevStart = $curStart->copy()->subMonth()->startOfMonth();
            $prevEnd = $curStart->copy()->subSecond();
            return [
                'cur_start' => $curStart,
                'cur_end' => $curEnd,
                'prev_start' => $prevStart,
                'prev_end' => $prevEnd,
                'current_label' => $curStart->format('M Y'),
                'previous_label' => $prevStart->format('M Y'),
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
            ];
        }

        throw new InvalidArgumentException('Invalid period type.');
    }

    private function formatRange(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('M j, Y');
        }

        return $start->format('M j').' – '.$end->format('M j, Y');
    }

    /**
     * @return array<string, mixed>
     */
    private function floatHeldData(Carbon $todayStart, string $tz): array
    {
        $preEventEscrow = 0.0;
        $pendingPayoutSettled = 0.0;

        $orgRateCache = [];
        $platformDefault = Dataset::merchantCommissionPercent();

        $txQuery = Transaction::query()
            ->with(['organization', 'schedule'])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('status', '!=', Transaction::STATUS['REFUNDED']);

        foreach ($txQuery->cursor() as $tx) {
            /** @var Transaction $tx */
            $gross = (float) $tx->total_amount;
            if ($gross <= 0) {
                continue;
            }

            $isSettledEvent = $this->isSettledEventTransaction($tx, $todayStart);
            if (! $isSettledEvent) {
                $preEventEscrow += $gross;
                continue;
            }

            $rate = $this->effectiveRateForTransaction($tx, $orgRateCache, $platformDefault);
            $merchantNet = round($gross - round($gross * ($rate / 100.0), 2), 2);
            if ($merchantNet <= 0) {
                continue;
            }

            $paidAt = $tx->paid_at ?? $tx->created_at;
            $paidCarbon = $paidAt instanceof Carbon
                ? $paidAt->copy()->timezone($tz)
                : Carbon::parse((string) $paidAt, $tz);

            $releaseDate = AffiliateCommissionAvailabilityService::availabilityDate($paidCarbon);
            if ($releaseDate->lte($todayStart)) {
                $pendingPayoutSettled += $merchantNet;
            }
        }

        $approvedPayoutsTotal = round((float) MerchantPayoutRequest::query()
            ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
            ->whereNull('void_at')
            ->sum('amount_requested'), 2);

        $reserveBuffer = round(($preEventEscrow + $pendingPayoutSettled) * 0.01, 2);
        $grossTotal = round($preEventEscrow + $pendingPayoutSettled + $reserveBuffer, 2);
        $total = max(0.0, round($grossTotal - $approvedPayoutsTotal, 2));
        $floatYieldAnnual = round($total * 0.046, 2);

        $lines = [
            ['key' => 'pre_event_escrow', 'label' => 'Pre-event escrow', 'value' => round($preEventEscrow, 2)],
            ['key' => 'pending_payout', 'label' => 'Pending payout (settled events)', 'value' => round($pendingPayoutSettled, 2)],
            ['key' => 'reserve', 'label' => 'Reserve (chargeback buffer)', 'value' => $reserveBuffer],
        ];

        if ($approvedPayoutsTotal > 0) {
            $lines[] = [
                'key' => 'approved_payouts',
                'label' => 'Less: approved merchant payouts',
                'value' => round(-$approvedPayoutsTotal, 2),
            ];
        }

        return [
            'total' => $total,
            'subtitle' => 'cash held pending event completion',
            'lines' => $lines,
            'yield_rate_pct' => 4.6,
            'float_yield_annual' => $floatYieldAnnual,
        ];
    }

    /**
     * @return array{meta: array{total: int}}
     */
    private function pendingPayoutsSummary(): array
    {
        $total = (int) MerchantPayoutRequest::query()
            ->where('status', MerchantPayoutRequest::STATUS_PENDING)
            ->count();

        return [
            'meta' => [
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginatedPendingPayouts(int $page, int $perPage, string $tz): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $paginator = MerchantPayoutRequest::query()
            ->with([
                'organization:uuid,name',
                'event:uuid,event_name',
                'organizationBank',
            ])
            ->where('status', MerchantPayoutRequest::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $mapped = $paginator->getCollection()->map(function (MerchantPayoutRequest $row) use ($tz): array {
            $created = $row->created_at?->copy()->timezone($tz);

            return [
                'uuid' => $row->uuid,
                'organizer' => $row->organization?->name ?? 'Unknown organizer',
                'event' => $row->event?->event_name ?? '—',
                'merchant_note' => $row->merchant_note,
                'gross' => (float) $row->amount_requested,
                'net_to_org' => (float) $row->amount_requested,
                'release' => $created ? $created->toDateString() : '',
                'requested_at' => $created?->toIso8601String(),
                'status' => 'pending',
                'status_label' => 'Pending request',
                'bank' => $this->mapOrganizationBank($row->organizationBank),
            ];
        })->values()->all();

        return [
            'rows' => $mapped,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @return array{meta: array{total: int, total_amount: float}}
     */
    private function payoutRequestsSummary(string $status): array
    {
        $baseQuery = MerchantPayoutRequest::query()->where('status', $status);

        return [
            'meta' => [
                'total' => (int) (clone $baseQuery)->count(),
                'total_amount' => round((float) (clone $baseQuery)->sum('amount_requested'), 2),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, int|float>}
     */
    public function paginatedPayoutRequests(string $status, int $page, int $perPage, string $tz): array
    {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $baseQuery = MerchantPayoutRequest::query()->where('status', $status);
        $totalAmount = round((float) (clone $baseQuery)->sum('amount_requested'), 2);

        $paginator = (clone $baseQuery)
            ->with(['organization:uuid,name'])
            ->orderByDesc('processed_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $mapped = $paginator->getCollection()->map(function (MerchantPayoutRequest $row) use ($tz, $status): array {
            $processed = $row->processed_at ?? $row->created_at;

            return [
                'uuid' => $row->uuid,
                'organizer' => $row->organization?->name ?? 'Unknown organizer',
                'amount' => (float) $row->amount_requested,
                'currency' => $row->currency ?? 'PHP',
                'notes' => $row->admin_notes ?: $row->merchant_note,
                'processed_at' => $processed?->timezone($tz)->toIso8601String(),
                'status' => $status,
                'status_label' => $status === MerchantPayoutRequest::STATUS_APPROVED
                    ? 'Approved'
                    : 'Declined',
            ];
        })->values()->all();

        return [
            'rows' => $mapped,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_amount' => $totalAmount,
            ],
        ];
    }

    private function clampPerPage(int $perPage): int
    {
        return min(50, max(5, $perPage));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapOrganizationBank(?OrganizationBank $bank): ?array
    {
        if ($bank === null) {
            return null;
        }

        return [
            'uuid' => $bank->uuid,
            'bank_name' => $bank->bank_name,
            'bank_branch' => $bank->bank_branch,
            'bank_address' => $bank->bank_address,
            'bank_account_name' => $bank->bank_account_name,
            'bank_account_number' => $bank->bank_account_number,
            'is_default' => (bool) $bank->is_default,
            'status' => $bank->status,
        ];
    }

    /**
     * @return array{meta: array{total: int, total_amount: float}}
     */
    private function remittancesSummary(): array
    {
        $listQuery = $this->remittanceHistoryQuery();

        return [
            'meta' => [
                'total' => (int) (clone $listQuery)->count(),
                'total_amount' => round((float) (clone $listQuery)
                    ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
                    ->whereNull('void_at')
                    ->sum('amount_requested'), 2),
            ],
        ];
    }

    /**
     * Approved and voided manual remittance entries shown in operator console history.
     *
     * @return \Illuminate\Database\Eloquent\Builder<MerchantPayoutRequest>
     */
    /**
     * @return \Illuminate\Database\Eloquent\Builder<MerchantPayoutRequest>
     */
    private function remittanceHistoryQuery(?string $organizationUuid = null)
    {
        $query = MerchantPayoutRequest::query()->whereIn('status', [
            MerchantPayoutRequest::STATUS_APPROVED,
            MerchantPayoutRequest::STATUS_VOID,
        ]);

        if ($organizationUuid !== null && $organizationUuid !== '') {
            $query->where('organization_uuid', $organizationUuid);
        }

        return $query;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, meta: array<string, int|float>}
     */
    public function paginatedRemittances(
        int $page,
        int $perPage,
        string $tz,
        ?string $organizationUuid = null,
    ): array {
        $perPage = $this->clampPerPage($perPage);
        $page = max(1, $page);

        $baseQuery = $this->remittanceHistoryQuery($organizationUuid);

        $totalAmount = round((float) (clone $baseQuery)
            ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
            ->whereNull('void_at')
            ->sum('amount_requested'), 2);

        $paginator = (clone $baseQuery)
            ->with([
                'organization:uuid,name',
                'event:uuid,event_name',
            ])
            ->orderByRaw('COALESCE(void_at, processed_at, created_at) DESC')
            ->paginate($perPage, ['*'], 'page', $page);

        $mapped = $paginator->getCollection()->map(function (MerchantPayoutRequest $row) use ($tz): array {
            $processed = $row->processed_at ?? $row->created_at;
            $isVoid = $row->status === MerchantPayoutRequest::STATUS_VOID;

            return [
                'uuid' => $row->uuid,
                'organizer' => $row->organization?->name ?? 'Unknown organizer',
                'event' => $row->event?->event_name ?? '—',
                'amount' => (float) $row->amount_requested,
                'currency' => $row->currency ?? 'PHP',
                'notes' => $row->admin_notes ?: $row->merchant_note,
                'status' => $row->status,
                'status_label' => $isVoid ? 'Void' : 'Approved',
                'processed_at' => $processed?->timezone($tz)->toIso8601String(),
                'void_at' => $row->void_at?->timezone($tz)->toIso8601String(),
            ];
        })->values()->all();

        return [
            'rows' => $mapped,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_amount' => $totalAmount,
            ],
        ];
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function organizersForRemittance(): array
    {
        return Organization::query()
            ->select('uuid', 'name')
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(fn (Organization $org): array => [
                'uuid' => $org->uuid,
                'name' => $org->name,
            ])
            ->values()
            ->all();
    }

    private function isSettledEventTransaction(Transaction $tx, Carbon $todayStart): bool
    {
        if ($tx->schedule && $tx->schedule->date_to) {
            $scheduleDate = $tx->schedule->date_to instanceof Carbon
                ? $tx->schedule->date_to->copy()->startOfDay()
                : Carbon::parse((string) $tx->schedule->date_to)->startOfDay();
            return $scheduleDate->lt($todayStart);
        }

        // Fallback: treat missing schedule as already settled after 1 day from payment.
        $paidAt = $tx->paid_at ?? $tx->created_at;
        if (! $paidAt) {
            return false;
        }
        $paid = $paidAt instanceof Carbon ? $paidAt->copy() : Carbon::parse((string) $paidAt);
        return $paid->startOfDay()->lt($todayStart);
    }

    /**
     * @param  array<string, float>  $orgRateCache
     */
    private function effectiveRateForTransaction(Transaction $tx, array &$orgRateCache, float $platformDefault): float
    {
        $orgUuid = $tx->organization_uuid;
        if ($orgUuid === null) {
            return $platformDefault;
        }

        if (! array_key_exists($orgUuid, $orgRateCache)) {
            $org = $tx->organization;
            if ($org === null) {
                $org = Organization::query()->where('uuid', $orgUuid)->first();
            }
            $orgRateCache[$orgUuid] = ($org !== null && $org->commission_percentage !== null)
                ? (float) $org->commission_percentage
                : $platformDefault;
        }

        return $orgRateCache[$orgUuid];
    }
}

