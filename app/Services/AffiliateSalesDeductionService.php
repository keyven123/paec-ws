<?php

namespace App\Services;

use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\TransactionOrder;
use Illuminate\Support\Collection;

/**
 * Allocates recorded (or computed) gross affiliate commission across transaction order lines
 * for exports and net sales summaries.
 */
final class AffiliateSalesDeductionService
{
    public static function grossAffiliateDeduction(
        ?string $affiliatePartnerUuid,
        float $transactionTotalAmount,
        Event $event,
        ?AffiliateConversion $credit,
    ): float {
        if (! $affiliatePartnerUuid) {
            return 0.0;
        }

        if ($credit !== null) {
            return max(0.0, round((float) $credit->commission_amount, 2));
        }

        if (! $event->affiliate_enabled) {
            return 0.0;
        }

        $pct = $event->affiliate_commission_percent;
        if ($pct === null || (float) $pct <= 0) {
            return 0.0;
        }

        if ($transactionTotalAmount <= 0) {
            return 0.0;
        }

        return round($transactionTotalAmount * ((float) $pct / 100), 2);
    }

    public static function orderLineNet(TransactionOrder $order, float $discount): float
    {
        $ta = (float) $order->total_amount;

        if ($ta > 0) {
            return $ta;
        }

        return max(0.0, (float) $order->price * (int) $order->quantity - $discount);
    }

    /**
     * Split gross affiliate commission across lines by each line's share of line net (pre-affiliate).
     *
     * @param  Collection<int, TransactionOrder>  $orders
     * @return array<string, float> transaction_order.uuid => deduction
     */
    public static function deductionPerOrderLine(Collection $orders, float $grossCommission): array
    {
        $sorted = $orders->sortBy('uuid')->values();

        if ($grossCommission <= 0 || $sorted->isEmpty()) {
            return $sorted->mapWithKeys(fn (TransactionOrder $o) => [$o->uuid => 0.0])->all();
        }

        $nets = [];
        foreach ($sorted as $order) {
            $nets[$order->uuid] = self::orderLineNet($order, 0.0);
        }

        $sumNet = array_sum($nets);
        if ($sumNet <= 0) {
            return $sorted->mapWithKeys(fn (TransactionOrder $o) => [$o->uuid => 0.0])->all();
        }

        $grossCommission = round($grossCommission, 2);
        $out = [];
        $remaining = $grossCommission;
        $n = $sorted->count();

        foreach ($sorted as $i => $order) {
            if ($i === $n - 1) {
                $out[$order->uuid] = round($remaining, 2);
            } else {
                $share = $grossCommission * ($nets[$order->uuid] / $sumNet);
                $allocated = round($share, 2);
                $out[$order->uuid] = $allocated;
                $remaining = round($remaining - $allocated, 2);
            }
        }

        return $out;
    }
}
