<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\EventTicket;

final class TicketMarkupService
{
    public const HEADER_MARKUP_TYPE_MIXED = 'mixed';

    public static function normalizeMarkupType(?string $type): ?string
    {
        if ($type === null || trim($type) === '') {
            return null;
        }

        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'percentage', 'percent', '%' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'amount', 'fixed', 'flat' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            default => $normalized,
        };
    }

    public static function unitMarkupAmount(EventTicket $ticket): float
    {
        $base = (float) $ticket->price;
        $type = self::normalizeMarkupType($ticket->markup_type);
        $value = (float) ($ticket->markup_value ?? 0);

        if ($type === null || $value <= 0) {
            return 0.0;
        }

        if ($type === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            return round($base * ($value / 100), 2);
        }

        if ($type === GeneralConstants::DISCOUNT_TYPES['AMOUNT']) {
            return round($value, 2);
        }

        return 0.0;
    }

    public static function displayUnitPrice(EventTicket $ticket): float
    {
        return round((float) $ticket->price + self::unitMarkupAmount($ticket), 2);
    }

    /**
     * Ticket-level discount applied to the base (merchant) portion.
     */
    public static function lineBaseDiscount(EventTicket $ticket, int $quantity): float
    {
        return self::lineComponentDiscount($ticket, (float) $ticket->price, $quantity);
    }

    /**
     * Ticket-level discount on the markup portion (percentage rules only).
     * Fixed/amount ticket discounts apply to merchant base (price) only.
     */
    public static function lineMarkupDiscount(EventTicket $ticket, int $quantity, float $unitMarkup): float
    {
        if ($unitMarkup <= 0 || $ticket->discount_type !== GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            return 0.0;
        }

        return self::lineComponentDiscount($ticket, $unitMarkup, $quantity);
    }

    private static function lineComponentDiscount(EventTicket $ticket, float $unitAmount, int $quantity): float
    {
        if ($unitAmount <= 0 || ! $ticket->discount_type || ! $ticket->discount_value) {
            return 0.0;
        }

        if ($ticket->discount_type === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE']) {
            return round(($unitAmount * (float) $ticket->discount_value / 100) * $quantity, 2);
        }

        return round((float) $ticket->discount_value * $quantity, 2);
    }

    /**
     * @return array{
     *   event_ticket_uuid: string,
     *   quantity: int,
     *   price: float,
     *   markup_type: string|null,
     *   markup_value: float|null,
     *   markup: float,
     *   markup_discount: float,
     *   discount: float,
     *   total_amount: float,
     *   valid_until?: string|null,
     *   seats?: mixed
     * }
     */
    public static function buildOrderLine(EventTicket $ticket, int $quantity, array $extras = []): array
    {
        $quantity = max(1, $quantity);
        $unitBase = (float) $ticket->price;
        $unitMarkup = self::unitMarkupAmount($ticket);
        $normalizedMarkupType = self::normalizeMarkupType($ticket->markup_type);
        $lineBase = round($unitBase * $quantity, 2);
        $lineMarkupGross = round($unitMarkup * $quantity, 2);
        $baseDiscount = self::lineBaseDiscount($ticket, $quantity);
        $markupDiscount = self::lineMarkupDiscount($ticket, $quantity, $unitMarkup);
        $lineMarkupNet = round(max(0, $lineMarkupGross - $markupDiscount), 2);
        $lineTotal = round(max(0, $lineBase - $baseDiscount) + $lineMarkupNet, 2);

        return array_merge([
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => $quantity,
            'price' => $unitBase,
            'markup_type' => $normalizedMarkupType,
            'markup_value' => $normalizedMarkupType !== null && $ticket->markup_value !== null
                ? (float) $ticket->markup_value
                : null,
            'markup' => $lineMarkupNet,
            'markup_discount' => $markupDiscount,
            'discount' => $baseDiscount,
            'total_amount' => $lineTotal,
        ], $extras);
    }

    /**
     * @param  list<array{markup_type: string|null, markup_value: float|null, markup: float, markup_discount: float, discount: float, price: float, quantity: int}>  $lines
     * @return array{markup_type: string|null, markup_value: float|null, markup_amount: float, markup_discount: float, discount: float, sub_total: float}
     */
    public static function aggregateFromOrderLines(array $lines): array
    {
        $subTotal = 0.0;
        $discount = 0.0;
        $markupAmount = 0.0;
        $markupDiscount = 0.0;
        $signatures = [];

        foreach ($lines as $line) {
            $qty = max(1, (int) $line['quantity']);
            $subTotal += round((float) $line['price'] * $qty, 2);
            $discount += (float) $line['discount'];
            $markupAmount += (float) $line['markup'];
            $markupDiscount += (float) ($line['markup_discount'] ?? 0);

            $type = $line['markup_type'] ?? null;
            $value = $line['markup_value'] ?? null;
            if ($type !== null && $type !== '' && $value !== null) {
                $signatures[$type.'|'.number_format((float) $value, 2, '.', '')] = [
                    'markup_type' => $type,
                    'markup_value' => (float) $value,
                ];
            }
        }

        $headerMarkup = self::resolveHeaderMarkupSnapshot(array_values($signatures));

        return [
            'sub_total' => round($subTotal, 2),
            'discount' => round($discount, 2),
            'markup_amount' => round($markupAmount, 2),
            'markup_discount' => round($markupDiscount, 2),
            'markup_type' => $headerMarkup['markup_type'],
            'markup_value' => $headerMarkup['markup_value'],
        ];
    }

    /**
     * @param  list<array{markup_type: string, markup_value: float}>  $signatures
     * @return array{markup_type: string|null, markup_value: float|null}
     */
    public static function resolveHeaderMarkupSnapshot(array $signatures): array
    {
        if ($signatures === []) {
            return ['markup_type' => null, 'markup_value' => null];
        }

        if (count($signatures) === 1) {
            return [
                'markup_type' => $signatures[0]['markup_type'],
                'markup_value' => $signatures[0]['markup_value'],
            ];
        }

        return [
            'markup_type' => self::HEADER_MARKUP_TYPE_MIXED,
            'markup_value' => null,
        ];
    }

    /**
     * Re-sum header totals from persisted order lines (authoritative for markup_amount).
     *
     * @param  iterable<int, object{price: mixed, quantity: mixed, discount: mixed, markup: mixed, markup_discount?: mixed, markup_type?: mixed|null, markup_value?: mixed|null}>  $orders
     * @return array{sub_total: float, discount: float, markup_amount: float, markup_discount: float, markup_type: string|null, markup_value: float|null}
     */
    public static function aggregateFromPersistedOrders(iterable $orders): array
    {
        $lines = [];

        foreach ($orders as $order) {
            $lines[] = [
                'price' => (float) $order->price,
                'quantity' => (int) $order->quantity,
                'discount' => (float) $order->discount,
                'markup' => (float) ($order->markup ?? 0),
                'markup_discount' => (float) ($order->markup_discount ?? 0),
                'markup_type' => $order->markup_type ?? null,
                'markup_value' => $order->markup_value !== null ? (float) $order->markup_value : null,
            ];
        }

        return self::aggregateFromOrderLines($lines);
    }

    /**
     * @param  list<array{event_ticket_uuid: string, quantity: int}>  $ticketPayloads
     * @return list<array<string, mixed>>
     */
    public static function buildOrderLinesFromTicketPayloads(array $ticketPayloads): array
    {
        $lines = [];

        foreach ($ticketPayloads as $ticketPayload) {
            $eventTicket = EventTicket::query()
                ->where('uuid', $ticketPayload['event_ticket_uuid'])
                ->first();

            if (! $eventTicket) {
                continue;
            }

            $lines[] = self::buildOrderLine(
                $eventTicket,
                (int) $ticketPayload['quantity'],
                [
                    'valid_until' => $ticketPayload['valid_until'] ?? null,
                    'seats' => $ticketPayload['seats'] ?? null,
                ],
            );
        }

        return $lines;
    }
}
