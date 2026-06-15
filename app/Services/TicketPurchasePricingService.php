<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class TicketPurchasePricingService
{
    /**
     * @return array{
     *   discount: float,
     *   net_selling_price: float,
     *   markup: float,
     *   gross_selling_price: float,
     *   tax_and_fees: float,
     *   gross_revenue: float,
     *   platform_fee: float,
     *   total_payout: float
     * }
     */
    public static function lineAmounts(
        Event $event,
        TransactionOrder $transactionOrder,
        float $unitPrice,
        int $quantity,
        float $lineDiscount,
        float $commissionRatePercent = 0,
        float $affiliateCommission = 0,
        float $markupPromoDiscount = 0,
    ): array {
        $lineBaseGross = round($unitPrice * $quantity, 2);
        $netSellingPrice = max(0, round($lineBaseGross - $lineDiscount, 2));
        $markup = max(0, round(
            (float) ($transactionOrder->markup ?? 0) + (float) ($transactionOrder->markup_discount ?? 0) - $markupPromoDiscount,
            2,
        ));
        $grossSellingPrice = round($netSellingPrice + $markup, 2);

        $taxAndFees = ActivityComplianceService::calculateForEvent($event, $grossSellingPrice)['tax_amount'];
        $grossRevenue = round($grossSellingPrice + $taxAndFees, 2);
        $platformFee = round($netSellingPrice * ($commissionRatePercent / 100), 2);
        $totalPayout = round($netSellingPrice - $affiliateCommission - $platformFee, 2);

        return [
            'discount' => round($lineDiscount, 2),
            'net_selling_price' => $netSellingPrice,
            'markup' => $markup,
            'gross_selling_price' => $grossSellingPrice,
            'tax_and_fees' => $taxAndFees,
            'gross_revenue' => $grossRevenue,
            'platform_fee' => $platformFee,
            'total_payout' => $totalPayout,
        ];
    }

    /**
     * Customer-facing amount per ticket: net selling + markup + taxes (gross revenue), split per seat/qty.
     */
    public static function customerGrossRevenuePerTicket(Ticket $ticket): float
    {
        $transaction = $ticket->transaction;
        if (! $transaction) {
            return max(0, round((float) ($ticket->price ?? 0), 2));
        }

        $transaction->loadMissing(['transactionOrders', 'event', 'tickets']);

        if ((float) ($transaction->total_amount ?? 0) <= 0) {
            return 0.0;
        }

        $order = $transaction->transactionOrders
            ->firstWhere('event_ticket_uuid', $ticket->event_ticket_uuid);

        if (! $order || ! $transaction->event) {
            return max(0, round((float) ($ticket->price ?? 0), 2));
        }

        $ordersSum = (float) $transaction->transactionOrders->sum('total_amount');
        $orderLineTotal = (float) $order->total_amount;
        $quantity = max(1, (int) $order->quantity);

        $promoShare = $ordersSum > 0
            ? ($orderLineTotal / $ordersSum) * (float) ($transaction->promo_code_discount ?? 0)
            : 0.0;

        $transaction->loadMissing('promoCode');
        $promoSplit = self::splitPercentagePromoDiscount(
            $order,
            (float) $order->price,
            $quantity,
            $promoShare,
            $transaction->promoCode?->discount_type,
        );

        $lineAmounts = self::lineAmounts(
            $transaction->event,
            $order,
            (float) $order->price,
            $quantity,
            (float) $order->discount + $promoSplit['base_promo'],
            0,
            0,
            $promoSplit['markup_promo'],
        );

        $ticketsOnLine = $transaction->tickets
            ->where('event_ticket_uuid', $ticket->event_ticket_uuid)
            ->reject(fn (Ticket $row) => $row->status === GeneralConstants::TICKET_STATUSES['TRANSFERRED'])
            ->count();

        $divisor = max(1, $ticketsOnLine > 0 ? $ticketsOnLine : $quantity);

        return round($lineAmounts['gross_revenue'] / $divisor, 2);
    }

    /**
     * Split a line's promo discount between merchant base and markup for percentage promos.
     *
     * @return array{base_promo: float, markup_promo: float}
     */
    public static function splitPercentagePromoDiscount(
        TransactionOrder $transactionOrder,
        float $unitPrice,
        int $quantity,
        float $linePromoDiscount,
        ?string $promoDiscountType,
    ): array {
        if ($linePromoDiscount <= 0) {
            return ['base_promo' => 0.0, 'markup_promo' => 0.0];
        }

        if ($promoDiscountType !== GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            return ['base_promo' => round($linePromoDiscount, 2), 'markup_promo' => 0.0];
        }

        $baseAfterTicketDiscount = max(0, round($unitPrice * $quantity, 2) - (float) $transactionOrder->discount);
        $markupGross = round(
            (float) ($transactionOrder->markup ?? 0) + (float) ($transactionOrder->markup_discount ?? 0),
            2,
        );
        $eligibleTotal = round($baseAfterTicketDiscount + $markupGross, 2);

        if ($eligibleTotal <= 0) {
            return ['base_promo' => round($linePromoDiscount, 2), 'markup_promo' => 0.0];
        }

        $basePromo = round($linePromoDiscount * ($baseAfterTicketDiscount / $eligibleTotal), 2);
        $markupPromo = round($linePromoDiscount - $basePromo, 2);

        return ['base_promo' => $basePromo, 'markup_promo' => $markupPromo];
    }

    /**
     * Export-aligned amounts for one paid order line (handles percentage promo split).
     *
     * @return array{
     *   discount: float,
     *   net_selling_price: float,
     *   markup: float,
     *   gross_selling_price: float,
     *   tax_and_fees: float,
     *   gross_revenue: float,
     *   platform_fee: float,
     *   total_payout: float
     * }
     */
    public static function lineAmountsForPaidOrder(
        Transaction $transaction,
        TransactionOrder $order,
        float $commissionRatePercent = 0,
        float $affiliateCommission = 0,
    ): array {
        $transaction->loadMissing(['promoCode', 'event']);
        $quantity = max(1, (int) $order->quantity);
        $unitPrice = (float) $order->price;
        $ordersSum = (float) $transaction->transactionOrders->sum('total_amount');
        $orderTotal = (float) $order->total_amount;

        $promoShare = $ordersSum > 0
            ? ($orderTotal / $ordersSum) * (float) ($transaction->promo_code_discount ?? 0)
            : 0.0;

        $promoSplit = self::splitPercentagePromoDiscount(
            $order,
            $unitPrice,
            $quantity,
            $promoShare,
            $transaction->promoCode?->discount_type,
        );

        $amounts = self::lineAmounts(
            $transaction->event,
            $order,
            $unitPrice,
            $quantity,
            (float) $order->discount + $promoSplit['base_promo'],
            $commissionRatePercent,
            $affiliateCommission,
            $promoSplit['markup_promo'],
        );

        $taxAndFees = $ordersSum > 0
            ? ($orderTotal / $ordersSum) * round((float) ($transaction->tax_amount ?? 0), 2)
            : round((float) ($transaction->tax_amount ?? 0), 2);
        $taxAndFees = round($taxAndFees, 2);
        $amounts['tax_and_fees'] = $taxAndFees;
        $amounts['gross_revenue'] = round($amounts['gross_selling_price'] + $taxAndFees, 2);

        return $amounts;
    }

    /**
     * Export-aligned line amounts for a paid transaction (net selling = unit price × qty − discounts).
     *
     * @return list<array{
     *   discount: float,
     *   net_selling_price: float,
     *   markup: float,
     *   gross_selling_price: float,
     *   tax_and_fees: float,
     *   gross_revenue: float,
     *   platform_fee: float,
     *   total_payout: float
     * }>
     */
    public static function transactionLineAmounts(Transaction $tx, float $commissionRatePercent = 0): array
    {
        $tx->loadMissing(['event', 'transactionOrders.eventTicket', 'affiliateConversion']);

        if ($tx->transactionOrders->isEmpty() || ! $tx->event) {
            return [self::legacyTransactionLineAmounts($tx, $commissionRatePercent)];
        }

        $ordersSum = (float) $tx->transactionOrders->sum('total_amount');
        $affiliateTotal = (float) ($tx->affiliateConversion?->commission_amount ?? 0.0);
        $lines = [];

        foreach ($tx->transactionOrders as $order) {
            $orderTotal = (float) $order->total_amount;

            $affiliateCommission = $ordersSum > 0
                ? ($orderTotal / $ordersSum) * $affiliateTotal
                : 0.0;

            $lines[] = self::lineAmountsForPaidOrder(
                $tx,
                $order,
                $commissionRatePercent,
                $affiliateCommission,
            );
        }

        return $lines;
    }

    public static function transactionNetSellingTotal(Transaction $tx, float $commissionRatePercent = 0): float
    {
        $sum = 0.0;
        foreach (self::transactionLineAmounts($tx, $commissionRatePercent) as $amounts) {
            $sum += $amounts['net_selling_price'];
        }

        return round($sum, 2);
    }

    /**
     * Sum export-aligned net selling for paid transactions matching the query.
     */
    public static function sumNetSellingForPaidTransactions(Builder|Relation $query): float
    {
        $builder = $query instanceof Relation ? $query->getQuery() : $query;
        $sum = 0.0;

        (clone $builder)
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->with(['event', 'transactionOrders.eventTicket', 'affiliateConversion'])
            ->orderBy('uuid')
            ->chunkById(200, function ($transactions) use (&$sum) {
                foreach ($transactions as $tx) {
                    /** @var Transaction $tx */
                    $sum += self::transactionNetSellingTotal($tx);
                }
            }, 'uuid');

        return round($sum, 2);
    }

    /**
     * Merchant-facing sales amount: net selling price minus platform fee (per export line).
     */
    public static function lineMerchantSalesAmount(array $amounts): float
    {
        return max(0, round($amounts['net_selling_price'] - $amounts['platform_fee'], 2));
    }

    public static function transactionMerchantSalesTotal(Transaction $tx, float $commissionRatePercent = 0): float
    {
        $sum = 0.0;
        foreach (self::transactionLineAmounts($tx, $commissionRatePercent) as $amounts) {
            $sum += self::lineMerchantSalesAmount($amounts);
        }

        return round($sum, 2);
    }

    /**
     * @return array{
     *   discount: float,
     *   net_selling_price: float,
     *   markup: float,
     *   gross_selling_price: float,
     *   tax_and_fees: float,
     *   gross_revenue: float,
     *   platform_fee: float,
     *   total_payout: float
     * }
     */
    private static function legacyTransactionLineAmounts(Transaction $tx, float $commissionRatePercent): array
    {
        $netSelling = max(
            0,
            round(
                (float) $tx->sub_total - (float) ($tx->discount ?? 0) - (float) ($tx->promo_code_discount ?? 0),
                2,
            ),
        );
        $markupAmount = round((float) ($tx->markup_amount ?? 0), 2);
        $taxAndFees = round((float) ($tx->tax_amount ?? 0), 2);
        $grossSellingPrice = round($netSelling + $markupAmount, 2);
        $grossRevenue = round($grossSellingPrice + $taxAndFees, 2);
        $platformFee = round($netSelling * ($commissionRatePercent / 100), 2);
        $affiliateCommission = (float) ($tx->affiliateConversion?->commission_amount ?? 0.0);

        return [
            'discount' => round((float) ($tx->discount ?? 0) + (float) ($tx->promo_code_discount ?? 0), 2),
            'net_selling_price' => $netSelling,
            'markup' => $markupAmount,
            'gross_selling_price' => $grossSellingPrice,
            'tax_and_fees' => $taxAndFees,
            'gross_revenue' => $grossRevenue,
            'platform_fee' => $platformFee,
            'total_payout' => round($netSelling - $affiliateCommission - $platformFee, 2),
        ];
    }
}
