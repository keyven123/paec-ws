<?php

namespace App\Helpers;

use App\Constants\GeneralConstants;
use App\Models\EventTicket;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\Event;
use App\Services\ActivityComplianceService;
use App\Services\TicketMarkupService;

class ComputationHelper
{
    /**
     * @param array $tickets
     * @return array
     */
    public static function generateTempTransactionData(array $tickets): array
    {
        $tempTransactionOrders = TicketMarkupService::buildOrderLinesFromTicketPayloads($tickets);
        $aggregated = TicketMarkupService::aggregateFromOrderLines($tempTransactionOrders);

        return [
            'total_amount' => $aggregated['sub_total'],
            'total_discount' => $aggregated['discount'],
            'markup_amount' => $aggregated['markup_amount'],
            'markup_discount' => $aggregated['markup_discount'],
            'markup_type' => $aggregated['markup_type'],
            'markup_value' => $aggregated['markup_value'],
            'temp_transaction_orders' => $tempTransactionOrders,
        ];
    }

    /**
     * @return array{
     *   sub_total: float,
     *   discount: float,
     *   markup_amount: float,
     *   markup_discount: float,
     *   markup_type: string|null,
     *   markup_value: float|null,
     *   tax_amount: float,
     *   total_amount: float,
     *   compliance_lines: list<array>,
     *   included_note: string|null,
     *   temp_transaction_orders: list<array>
     * }
     */
    public static function buildCheckoutPricing(Event $event, array $tickets, float $promoCodeDiscount = 0): array
    {
        $cart = self::generateTempTransactionData($tickets);

        $complianceAmounts = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => $cart['total_amount'],
            'discount' => $cart['total_discount'],
            'promo_code_discount' => $promoCodeDiscount,
            'markup_amount' => $cart['markup_amount'],
        ]);

        return array_merge($cart, $complianceAmounts);
    }

    /**
     * Customer-facing cart total before promo and tax (base after ticket discounts + net markup).
     *
     * @param  array{total_amount?: float, total_discount?: float, markup_amount?: float, temp_transaction_orders?: list<array{total_amount?: float}>}  $cartPreview
     */
    public static function promoEligibleCartTotal(array $cartPreview): float
    {
        if (! empty($cartPreview['temp_transaction_orders'])) {
            $total = 0.0;
            foreach ($cartPreview['temp_transaction_orders'] as $order) {
                $total += (float) ($order['total_amount'] ?? 0);
            }

            return round($total, 2);
        }

        return round(
            (float) ($cartPreview['total_amount'] ?? 0)
            - (float) ($cartPreview['total_discount'] ?? 0)
            + (float) ($cartPreview['markup_amount'] ?? 0),
            2
        );
    }

    public static function calculateTotalDiscount(EventTicket $eventTicket, int $quantity): float
    {
        return TicketMarkupService::lineBaseDiscount($eventTicket, $quantity);
    }

    public static function calculatePromoCodeDiscount(PromoCode $promoCode, float $totalAmount): float
    {
        if ($totalAmount <= 0) {
            return 0.0;
        }

        $promoCodeDiscountType = $promoCode->discount_type;
        if ($promoCodeDiscountType === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            $promoCodeDiscountValue = ($promoCode->discount_value / 100) * $totalAmount;
        } else {
            $promoCodeDiscountValue = min((float) $promoCode->discount_value, $totalAmount);
        }

        return round($promoCodeDiscountValue, 2);
    }

    public static function calculateRefundDiscount(Transaction $transaction, TransactionOrder $transactionOrder): float
    {
        $discount = (($transaction->discount + $transaction->promo_code_discount) * ($transactionOrder->total_amount / $transaction->total_amount));
        return $discount;
    }
}
