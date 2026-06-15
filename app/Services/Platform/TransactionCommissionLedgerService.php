<?php

namespace App\Services\Platform;

use App\Models\TransactionCommission;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the denormalized {@see TransactionCommission} ledger over a
 * date range. Returns totals plus per-provider and per-method breakdowns
 * suitable for the admin Platform P&L UI's "Commission Ledger" section.
 *
 * All money figures are decimal:2; counts are ints. The grouped rows are
 * sorted by gross_amount descending so the largest contributors surface
 * first in the UI.
 */
class TransactionCommissionLedgerService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $base = TransactionCommission::query()
            ->where('transaction_type', TransactionCommission::TYPE['TRANSACTION'])
            ->whereBetween('date_paid', [$from, $to]);

        $totals = (clone $base)
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_amount')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net_amount')
            ->selectRaw('COALESCE(SUM(ticketoc_commission), 0) AS ticketoc_commission')
            ->selectRaw('COALESCE(SUM(ticketoc_net_commission), 0) AS ticketoc_net_commission')
            ->selectRaw('COALESCE(SUM(agent_commission), 0) AS agent_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_commission), 0) AS payment_gateway_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_fixed_fee), 0) AS payment_gateway_fixed_fee')
            ->first();

        $totalGatewayFee = (float) ($totals->payment_gateway_commission ?? 0)
            + (float) ($totals->payment_gateway_fixed_fee ?? 0);

        $byProvider = (clone $base)
            ->selectRaw('payment_provider')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_amount')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net_amount')
            ->selectRaw('COALESCE(SUM(ticketoc_commission), 0) AS ticketoc_commission')
            ->selectRaw('COALESCE(SUM(ticketoc_net_commission), 0) AS ticketoc_net_commission')
            ->selectRaw('COALESCE(SUM(agent_commission), 0) AS agent_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_commission), 0) AS payment_gateway_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_fixed_fee), 0) AS payment_gateway_fixed_fee')
            ->groupBy('payment_provider')
            ->orderByRaw('SUM(gross_amount) DESC')
            ->get()
            ->map(fn ($row) => $this->shapeRow($row, includeProvider: true, includeMethod: false))
            ->all();

        $byMethod = (clone $base)
            ->selectRaw('payment_provider')
            ->selectRaw('payment_method')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw('COALESCE(SUM(gross_amount), 0) AS gross_amount')
            ->selectRaw('COALESCE(SUM(net_amount), 0) AS net_amount')
            ->selectRaw('COALESCE(SUM(ticketoc_commission), 0) AS ticketoc_commission')
            ->selectRaw('COALESCE(SUM(ticketoc_net_commission), 0) AS ticketoc_net_commission')
            ->selectRaw('COALESCE(SUM(agent_commission), 0) AS agent_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_commission), 0) AS payment_gateway_commission')
            ->selectRaw('COALESCE(SUM(payment_gateway_fixed_fee), 0) AS payment_gateway_fixed_fee')
            ->groupBy('payment_provider', 'payment_method')
            ->orderByRaw('SUM(gross_amount) DESC')
            ->get()
            ->map(fn ($row) => $this->shapeRow($row, includeProvider: true, includeMethod: true))
            ->all();

        // "Cash in <provider>" = gross of paid txns − gateway fees we paid the
        // gateway. This is the amount that *actually* sits in the gateway's
        // account before TicketOC settles to organizers and affiliates.
        $cashByProvider = [];
        foreach ($byProvider as $row) {
            $provider = $row['payment_provider'] ?? null;
            if (! is_string($provider) || $provider === '') {
                continue;
            }
            $cashByProvider[$provider] = round(
                (float) $row['gross_amount']
                - (float) $row['payment_gateway_commission']
                - (float) $row['payment_gateway_fixed_fee'],
                2
            );
        }

        return [
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'totals' => [
                'transaction_count' => (int) ($totals->transaction_count ?? 0),
                'gross_amount' => round((float) ($totals->gross_amount ?? 0), 2),
                'net_amount' => round((float) ($totals->net_amount ?? 0), 2),
                'ticketoc_commission' => round((float) ($totals->ticketoc_commission ?? 0), 2),
                'ticketoc_net_commission' => round((float) ($totals->ticketoc_net_commission ?? 0), 2),
                'agent_commission' => round((float) ($totals->agent_commission ?? 0), 2),
                'payment_gateway_commission' => round((float) ($totals->payment_gateway_commission ?? 0), 2),
                'payment_gateway_fixed_fee' => round((float) ($totals->payment_gateway_fixed_fee ?? 0), 2),
                'payment_gateway_total' => round($totalGatewayFee, 2),
            ],
            'cash_by_provider' => $cashByProvider,
            'by_provider' => $byProvider,
            'by_method' => $byMethod,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shapeRow(object $row, bool $includeProvider, bool $includeMethod): array
    {
        $shaped = [
            'transaction_count' => (int) ($row->transaction_count ?? 0),
            'gross_amount' => round((float) ($row->gross_amount ?? 0), 2),
            'net_amount' => round((float) ($row->net_amount ?? 0), 2),
            'ticketoc_commission' => round((float) ($row->ticketoc_commission ?? 0), 2),
            'ticketoc_net_commission' => round((float) ($row->ticketoc_net_commission ?? 0), 2),
            'agent_commission' => round((float) ($row->agent_commission ?? 0), 2),
            'payment_gateway_commission' => round((float) ($row->payment_gateway_commission ?? 0), 2),
            'payment_gateway_fixed_fee' => round((float) ($row->payment_gateway_fixed_fee ?? 0), 2),
        ];

        $shaped['payment_gateway_total'] = round(
            $shaped['payment_gateway_commission'] + $shaped['payment_gateway_fixed_fee'],
            2
        );

        if ($includeProvider) {
            $shaped = ['payment_provider' => (string) ($row->payment_provider ?? '')] + $shaped;
        }

        if ($includeMethod) {
            $shaped['payment_method'] = $row->payment_method !== null
                ? (string) $row->payment_method
                : null;
        }

        return $shaped;
    }
}
