<?php

namespace App\Services\Organizer;

use App\Models\Dataset;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\TicketPurchasePricingService;
use Carbon\Carbon;

class OrganizerAccountingBalanceService
{
    public function commissionRate(?Organization $org): float
    {
        return ($org !== null && $org->commission_percentage !== null)
            ? (float) $org->commission_percentage
            : Dataset::merchantCommissionPercent();
    }

    /**
     * @return array{0: float, 1: float} [schedule_pending_net, matured_net]
     */
    public function remittanceTotals(string $orgUuid, float $rate, ?string $eventUuid = null): array
    {
        if ($eventUuid !== null) {
            return $this->remittanceTotalsScoped($orgUuid, $rate, $eventUuid);
        }

        $schedulePending = 0.0;
        $maturedNet = 0.0;

        foreach ($this->organizationEventUuids($orgUuid) as $uuid) {
            [$schedulePendingPart, $maturedNetPart] = $this->remittanceTotalsScoped($orgUuid, $rate, $uuid);
            $schedulePending += $schedulePendingPart;
            $maturedNet += $maturedNetPart;
        }

        [$schedulePendingUnassigned, $maturedNetUnassigned] = $this->remittanceTotalsUnassigned($orgUuid, $rate);
        $schedulePending += $schedulePendingUnassigned;
        $maturedNet += $maturedNetUnassigned;

        return [$schedulePending, $maturedNet];
    }

    public function availableForPayout(string $orgUuid, ?string $eventUuid = null): float
    {
        if ($eventUuid !== null) {
            return $this->availableForPayoutScoped($orgUuid, $eventUuid);
        }

        $total = 0.0;
        foreach ($this->organizationEventUuids($orgUuid) as $uuid) {
            $total += $this->availableForPayoutScoped($orgUuid, $uuid);
        }

        $total += $this->availableForPayoutUnassigned($orgUuid);

        return round($total, 2);
    }

    /**
     * @return array{0: float, 1: float} [schedule_pending_net, matured_net]
     */
    private function remittanceTotalsScoped(string $orgUuid, float $rate, string $eventUuid): array
    {
        $txQuery = $this->paidTransactionsQuery($orgUuid)
            ->where('event_uuid', $eventUuid);

        return $this->sumRemittanceTotalsFromTransactions($txQuery, $rate);
    }

    /**
     * @return array{0: float, 1: float} [schedule_pending_net, matured_net]
     */
    private function remittanceTotalsUnassigned(string $orgUuid, float $rate): array
    {
        $txQuery = $this->paidTransactionsQuery($orgUuid)
            ->whereNull('event_uuid');

        return $this->sumRemittanceTotalsFromTransactions($txQuery, $rate);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Transaction>  $txQuery
     * @return array{0: float, 1: float}
     */
    private function sumRemittanceTotalsFromTransactions($txQuery, float $rate): array
    {
        $schedulePending = 0.0;
        $maturedNet = 0.0;

        $tz = AffiliateCommissionAvailabilityService::timezone();
        $today = Carbon::now($tz)->startOfDay();

        foreach ($txQuery->cursor() as $tx) {
            $net = $this->merchantTotalPayoutForTransaction($tx, $rate);
            if ($net <= 0) {
                continue;
            }
            if ($this->remittanceScheduleIsPending($tx, $today)) {
                $schedulePending += $net;
            } else {
                $maturedNet += $net;
            }
        }

        return [$schedulePending, $maturedNet];
    }

    private function availableForPayoutScoped(string $orgUuid, string $eventUuid): float
    {
        $org = Organization::query()->where('uuid', $orgUuid)->first();
        $rate = $this->commissionRate($org);

        [, $maturedNet] = $this->remittanceTotalsScoped($orgUuid, $rate, $eventUuid);

        $approvedPayouts = (float) MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->where('event_uuid', $eventUuid)
            ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
            ->whereNull('void_at')
            ->sum('amount_requested');

        $pendingPayoutRequests = (float) MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->where('event_uuid', $eventUuid)
            ->where('status', MerchantPayoutRequest::STATUS_PENDING)
            ->sum('amount_requested');

        return round($maturedNet - $approvedPayouts - $pendingPayoutRequests, 2);
    }

    private function availableForPayoutUnassigned(string $orgUuid): float
    {
        $org = Organization::query()->where('uuid', $orgUuid)->first();
        $rate = $this->commissionRate($org);

        [, $maturedNet] = $this->remittanceTotalsUnassigned($orgUuid, $rate);

        $payoutQuery = $this->organizationWidePayoutQuery($orgUuid);

        $approvedPayouts = (float) (clone $payoutQuery)
            ->where('status', MerchantPayoutRequest::STATUS_APPROVED)
            ->whereNull('void_at')
            ->sum('amount_requested');

        $pendingPayoutRequests = (float) (clone $payoutQuery)
            ->where('status', MerchantPayoutRequest::STATUS_PENDING)
            ->sum('amount_requested');

        return round($maturedNet - $approvedPayouts - $pendingPayoutRequests, 2);
    }

    /**
     * Payouts not tied to a current organization event (null or orphaned event_uuid).
     *
     * @return \Illuminate\Database\Eloquent\Builder<MerchantPayoutRequest>
     */
    private function organizationWidePayoutQuery(string $orgUuid)
    {
        $eventUuids = $this->organizationEventUuids($orgUuid);

        return MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->where(function ($query) use ($eventUuids) {
                $query->whereNull('event_uuid');
                if ($eventUuids->isNotEmpty()) {
                    $query->orWhereNotIn('event_uuid', $eventUuids->all());
                }
            });
    }

    /**
     * @return Collection<int, string>
     */
    private function organizationEventUuids(string $orgUuid): Collection
    {
        return Event::query()
            ->where('organization_uuid', $orgUuid)
            ->pluck('uuid');
    }

    public function baseAmountForTransaction(Transaction $tx): float
    {
        return round((float) $tx->sub_total - (float) ($tx->discount ?? 0), 2);
    }

    public function taxAndFeesForTransaction(Transaction $tx): float
    {
        return round((float) ($tx->tax_amount ?? 0), 2);
    }

    public function commissionableGrossForTransaction(Transaction $tx): float
    {
        return round((float) $tx->total_amount - $this->taxAndFeesForTransaction($tx), 2);
    }

    public function commissionAmountForTransaction(Transaction $tx, float $commissionRate): float
    {
        $base = $this->commissionableGrossForTransaction($tx);

        return round($base * ($commissionRate / 100.0), 2);
    }

    public function merchantNetForTransaction(Transaction $tx, float $commissionRate): float
    {
        return $this->merchantTotalPayoutForTransaction($tx, $commissionRate);
    }

    /**
     * Sum of merchant export "Total Payout" across all order lines (matches purchasers CSV).
     */
    public function merchantTotalPayoutForTransaction(Transaction $tx, float $commissionRate): float
    {
        $total = 0.0;

        foreach ($this->merchantExportLinesForTransaction($tx, $commissionRate) as $line) {
            $total += (float) $line['total_payout'];
        }

        return round($total, 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function merchantExportLinesForTransaction(Transaction $tx, float $commissionRate): array
    {
        $tx->loadMissing(['event', 'transactionOrders.eventTicket', 'affiliateConversion']);

        if ($tx->transactionOrders->isEmpty()) {
            return [$this->legacyMerchantExportLine($tx, $commissionRate)];
        }

        if (! $tx->event) {
            return [];
        }

        $ordersSum = (float) $tx->transactionOrders->sum('total_amount');
        $affiliateTotal = (float) ($tx->affiliateConversion?->commission_amount ?? 0.0);
        $lines = [];

        foreach ($tx->transactionOrders as $order) {
            $orderTotal = (float) $order->total_amount;
            $quantity = max(1, (int) $order->quantity);

            $affiliateCommission = $ordersSum > 0
                ? ($orderTotal / $ordersSum) * $affiliateTotal
                : 0.0;

            $amounts = TicketPurchasePricingService::lineAmountsForPaidOrder(
                $tx,
                $order,
                $commissionRate,
                $affiliateCommission,
            );

            if ($amounts['total_payout'] <= 0) {
                continue;
            }

            $lines[] = array_merge($amounts, [
                'uuid' => $order->uuid,
                'transaction_uuid' => $tx->uuid,
                'quantity' => $quantity,
                'unit_price' => round((float) $order->price, 2),
                'affiliate_commission' => round($affiliateCommission, 2),
            ]);
        }

        if ($lines === []) {
            return [$this->legacyMerchantExportLine($tx, $commissionRate)];
        }

        return $lines;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Transaction>
     */
    private function paidTransactionsQuery(string $orgUuid)
    {
        return Transaction::query()
            ->with([
                'event',
                'transactionOrders.eventTicket',
                'affiliateConversion',
            ])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->where('status', '!=', Transaction::STATUS['REFUNDED'])
            ->where('organization_uuid', $orgUuid);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyMerchantExportLine(Transaction $tx, float $commissionRate): array
    {
        $netSelling = max(
            0,
            round(
                (float) $tx->sub_total - (float) ($tx->discount ?? 0) - (float) ($tx->promo_code_discount ?? 0),
                2,
            ),
        );
        $affiliateCommission = (float) ($tx->affiliateConversion?->commission_amount ?? 0.0);
        $platformFee = round($netSelling * ($commissionRate / 100), 2);
        $totalPayout = round($netSelling - $affiliateCommission - $platformFee, 2);

        return [
            'uuid' => $tx->uuid,
            'transaction_uuid' => $tx->uuid,
            'quantity' => 1,
            'unit_price' => round((float) $tx->sub_total, 2),
            'discount' => round((float) ($tx->discount ?? 0) + (float) ($tx->promo_code_discount ?? 0), 2),
            'net_selling_price' => $netSelling,
            'affiliate_commission' => round($affiliateCommission, 2),
            'platform_fee' => $platformFee,
            'total_payout' => max(0, $totalPayout),
        ];
    }

    public function remittanceScheduleIsPending(Transaction $tx, Carbon $todayStart): bool
    {
        $paidAt = $tx->paid_at ?? $tx->created_at;
        if ($paidAt === null) {
            return false;
        }

        $tz = AffiliateCommissionAvailabilityService::timezone();

        $paidCarbon = $paidAt instanceof Carbon
            ? $paidAt->copy()
            : Carbon::parse($paidAt, $tz);

        $remittanceDate = AffiliateCommissionAvailabilityService::availabilityDate($paidCarbon);

        return $remittanceDate->gt($todayStart);
    }
}
