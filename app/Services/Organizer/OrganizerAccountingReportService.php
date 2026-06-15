<?php

namespace App\Services\Organizer;

use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrganizerAccountingReportService
{
    public function __construct(
        protected OrganizerAccountingBalanceService $balanceService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $orgUuid, ?string $eventUuid = null): array
    {
        $org = Organization::query()->where('uuid', $orgUuid)->first();
        $rate = $this->balanceService->commissionRate($org);

        [$pendingTotalPayout, $maturedTotalPayout] = $this->balanceService->remittanceTotals($orgUuid, $rate, $eventUuid);
        $availableForCashout = $this->balanceService->availableForPayout($orgUuid, $eventUuid);

        $approvedPayouts = $this->sumApprovedPayouts($orgUuid, $eventUuid);

        $pendingTotalPayout = round($pendingTotalPayout, 2);
        $maturedTotalPayout = round($maturedTotalPayout, 2);

        return [
            'available' => $availableForCashout,
            'available_for_cashout' => $availableForCashout,
            'matured_total_payout' => $maturedTotalPayout,
            'pending' => $pendingTotalPayout,
            'pending_total_payout' => $pendingTotalPayout,
            'total_cashout' => round($approvedPayouts, 2),
            'commission_percentage' => $org?->commission_percentage !== null
                ? (float) $org->commission_percentage
                : null,
            'effective_commission_percentage' => $rate,
            'currency' => 'PHP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactions(string $orgUuid, string $bucket, int $page, int $perPage, ?string $eventUuid = null): array
    {
        $org = Organization::query()->where('uuid', $orgUuid)->first();
        $rate = $this->balanceService->commissionRate($org);

        $perPage = min(50, max(5, $perPage));
        $page = max(1, $page);

        $tz = AffiliateCommissionAvailabilityService::timezone();
        $today = Carbon::now($tz)->startOfDay();
        $wantPending = $bucket === 'pending';

        $total = 0;
        foreach ($this->paidTransactionsCursor($orgUuid, $eventUuid) as $tx) {
            if ($this->balanceService->remittanceScheduleIsPending($tx, $today) !== $wantPending) {
                continue;
            }
            foreach ($this->balanceService->merchantExportLinesForTransaction($tx, $rate) as $line) {
                if ((float) $line['total_payout'] <= 0) {
                    continue;
                }
                $total++;
            }
        }

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $skip = ($page - 1) * $perPage;
        $rows = [];
        $matchedIndex = 0;

        foreach ($this->paidTransactionsCursor($orgUuid, $eventUuid) as $tx) {
            if ($this->balanceService->remittanceScheduleIsPending($tx, $today) !== $wantPending) {
                continue;
            }
            foreach ($this->merchantExportRowsForTransaction($tx, $rate, $tz, $today) as $row) {
                if ((float) $row['total_payout'] <= 0) {
                    continue;
                }
                if ($matchedIndex++ < $skip) {
                    continue;
                }
                if (count($rows) >= $perPage) {
                    break 2;
                }
                $rows[] = $row;
            }
        }

        return [
            'bucket' => $bucket,
            'commission_percentage' => $org?->commission_percentage !== null
                ? (float) $org->commission_percentage
                : null,
            'effective_commission_percentage' => $rate,
            'transactions' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function remittanceBuckets(string $orgUuid, string $bucket, ?string $eventUuid = null): array
    {
        $org = Organization::query()->where('uuid', $orgUuid)->first();
        $rate = $this->balanceService->commissionRate($org);

        $tz = AffiliateCommissionAvailabilityService::timezone();
        $today = Carbon::now($tz)->startOfDay();
        $wantPending = $bucket === 'pending';

        $months = [];
        $totalsMerchantNet = 0.0;
        $totalsCount = 0;

        foreach ($this->paidTransactionsCursor($orgUuid, $eventUuid) as $tx) {
            if ($this->balanceService->remittanceScheduleIsPending($tx, $today) !== $wantPending) {
                continue;
            }

            $remittanceMeta = $this->remittanceMetaForTransaction($tx, $tz, $today);

            foreach ($this->merchantExportRowsForTransaction($tx, $rate, $tz, $today) as $row) {
                if ((float) $row['total_payout'] <= 0) {
                    continue;
                }

                $totalsMerchantNet += (float) $row['total_payout'];
                $totalsCount++;

                $remittanceAt = Carbon::parse($remittanceMeta['remittance_date'], $tz)->startOfDay();
                $ym = $remittanceAt->format('Y-m');
                $day = (int) $remittanceAt->day;
                $slotKey = $day === 15 ? 'release_15' : 'release_30';

                if (! isset($months[$ym])) {
                    $months[$ym] = [
                        'key' => $ym,
                        'title' => Carbon::createFromFormat('Y-m-d', $ym.'-01', $tz)->format('F Y'),
                        'release_15' => [
                            'release_date' => null,
                            'total_merchant_net' => 0.0,
                            'transactions' => [],
                        ],
                        'release_30' => [
                            'release_date' => null,
                            'total_merchant_net' => 0.0,
                            'transactions' => [],
                        ],
                    ];
                }

                $months[$ym][$slotKey]['release_date'] = $remittanceMeta['remittance_date'];
                $months[$ym][$slotKey]['total_merchant_net'] += (float) $row['total_payout'];
                $months[$ym][$slotKey]['transactions'][] = $row;
            }
        }

        krsort($months);

        foreach ($months as &$monthBlock) {
            foreach (['release_15', 'release_30'] as $sk) {
                $monthBlock[$sk]['total_merchant_net'] = round((float) $monthBlock[$sk]['total_merchant_net'], 2);
                usort(
                    $monthBlock[$sk]['transactions'],
                    static function (array $a, array $b): int {
                        return strcmp((string) ($b['paid_at'] ?? ''), (string) ($a['paid_at'] ?? ''));
                    }
                );
            }
        }
        unset($monthBlock);

        return [
            'bucket' => $bucket,
            'commission_percentage' => $org?->commission_percentage !== null
                ? (float) $org->commission_percentage
                : null,
            'effective_commission_percentage' => $rate,
            'totals' => [
                'merchant_net_sum' => round((float) $totalsMerchantNet, 2),
                'transaction_count' => $totalsCount,
            ],
            'months' => array_values($months),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payoutRequests(
        string $orgUuid,
        ?string $eventUuid = null,
        int $pendingPage = 1,
        int $successPage = 1,
        int $declinedPage = 1,
        int $perPage = 10,
    ): array {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $perPage = min(50, max(5, $perPage));

        $baseQuery = fn () => MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->when($eventUuid !== null, fn ($query) => $query->where('event_uuid', $eventUuid))
            ->with('event');

        return [
            'pending' => $this->paginatedMerchantPayoutRequests(
                $baseQuery,
                MerchantPayoutRequest::STATUS_PENDING,
                max(1, $pendingPage),
                $perPage,
                $tz,
                orderByProcessed: false,
            ),
            'success' => $this->paginatedMerchantPayoutRequests(
                $baseQuery,
                MerchantPayoutRequest::STATUS_APPROVED,
                max(1, $successPage),
                $perPage,
                $tz,
                orderByProcessed: true,
            ),
            'declined' => $this->paginatedMerchantPayoutRequests(
                $baseQuery,
                MerchantPayoutRequest::STATUS_DECLINED,
                max(1, $declinedPage),
                $perPage,
                $tz,
                orderByProcessed: true,
            ),
        ];
    }

    /**
     * @param  callable(): \Illuminate\Database\Eloquent\Builder<MerchantPayoutRequest>  $baseQuery
     * @return array{rows: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function paginatedMerchantPayoutRequests(
        callable $baseQuery,
        string $status,
        int $page,
        int $perPage,
        string $tz,
        bool $orderByProcessed,
    ): array {
        $query = $baseQuery()->where('status', $status);

        if ($status === MerchantPayoutRequest::STATUS_APPROVED) {
            $query->whereNull('void_at');
        }

        if ($orderByProcessed) {
            $query->orderByDesc('processed_at')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginator->getCollection()->map(function (MerchantPayoutRequest $row) use ($tz): array {
            return [
                'uuid' => $row->uuid,
                'event_uuid' => $row->event_uuid,
                'event_name' => $row->event?->event_name,
                'amount_requested' => (float) $row->amount_requested,
                'currency' => $row->currency,
                'status' => $row->status,
                'merchant_note' => $row->merchant_note,
                'admin_notes' => $row->admin_notes,
                'created_at' => $row->created_at?->timezone($tz)->toIso8601String(),
                'processed_at' => $row->processed_at?->timezone($tz)->toIso8601String(),
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function sumApprovedPayouts(string $orgUuid, ?string $eventUuid = null): float
    {
        $query = MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
            ->whereNull('void_at');

        if ($eventUuid !== null) {
            $query->where('event_uuid', $eventUuid);
        }

        return (float) $query->sum('amount_requested');
    }

    /**
     * @return \Generator<int, Transaction>
     */
    private function paidTransactionsCursor(string $orgUuid, ?string $eventUuid = null): \Generator
    {
        $query = Transaction::query()
            ->with([
                'event' => function ($q) {
                    $q->select('uuid', 'event_name');
                },
                'transactionOrders.eventTicket',
                'affiliateConversion',
            ])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('status', '!=', Transaction::STATUS['REFUNDED'])
            ->where('organization_uuid', $orgUuid)
            ->when($eventUuid !== null, fn ($q) => $q->where('event_uuid', $eventUuid))
            ->orderByDesc(DB::raw('COALESCE(paid_at, created_at)'));

        foreach ($query->cursor() as $tx) {
            yield $tx;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function merchantExportRowsForTransaction(
        Transaction $tx,
        float $rate,
        string $tz,
        Carbon $today,
    ): array {
        $paidAt = $tx->paid_at ?? $tx->created_at;
        $remittanceMeta = $this->remittanceMetaForTransaction($tx, $tz, $today);

        $rows = [];
        foreach ($this->balanceService->merchantExportLinesForTransaction($tx, $rate) as $line) {
            $rows[] = array_merge($line, [
                'order_number' => $tx->order_number,
                'event_name' => $tx->event?->event_name,
                'paid_at' => $paidAt ? Carbon::parse($paidAt)->timezone($tz)->toIso8601String() : null,
                'commission_percentage' => $rate,
                'remittance_date' => $remittanceMeta['remittance_date'],
                'remittance_status' => $remittanceMeta['remittance_status'],
            ]);
        }

        return $rows;
    }

    /**
     * @return array{remittance_date: string, remittance_status: string}
     */
    private function remittanceMetaForTransaction(Transaction $tx, string $tz, Carbon $today): array
    {
        $paidAt = $tx->paid_at ?? $tx->created_at;
        $paidCarbon = $paidAt instanceof Carbon
            ? $paidAt->copy()
            : ($paidAt ? Carbon::parse($paidAt, $tz) : Carbon::now($tz));

        $remittanceDate = AffiliateCommissionAvailabilityService::availabilityDate($paidCarbon);

        return [
            'remittance_date' => $remittanceDate->toDateString(),
            'remittance_status' => $remittanceDate->gt($today) ? 'pending' : 'available',
        ];
    }
}
