<?php

namespace App\Services\Platform;

use App\Models\Dataset;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use Illuminate\Support\Facades\Log;

/**
 * Builds and persists denormalized commission ledger rows for paid
 * transactions. Snapshots organization / affiliate / gateway rates so that
 * historical accounting is stable even if the rate datasets change later.
 */
class TransactionCommissionService
{

    /**
     * Record a 'transaction' (sale) ledger row from a successfully-paid
     * Transaction. Idempotent on the (accountable, transaction_type) pair.
     *
     * Skips: free tickets, non-gateway providers, zero-amount transactions,
     * and transactions that aren't actually marked as paid.
     */
    public function recordPaidTransaction(Transaction $transaction): ?TransactionCommission
    {
        if ($transaction->payment_status !== Transaction::PAYMENT_STATUS['PAID']) {
            return null;
        }

        $provider = strtolower((string) $transaction->payment_provider);
        if ($provider === '' || $provider === 'free') {
            return null;
        }

        $gross = (float) $transaction->total_amount;
        if ($gross <= 0.0) {
            return null;
        }

        try {
            return TransactionCommission::firstOrCreate(
                [
                    'accountable_type' => Transaction::class,
                    'accountable_id' => $transaction->uuid,
                    'transaction_type' => TransactionCommission::TYPE['TRANSACTION'],
                ],
                $this->buildAttributes($transaction, $gross, $provider)
            );
        } catch (\Throwable $e) {
            Log::error('Transaction commission recording failed', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a 'refund' ledger row from a cancelled/refunded Transaction row.
     * Idempotent on the (accountable, transaction_type) pair.
     *
     * @param  string|null  $originalPaidAt  ISO/datetime of the original
     *                                        transaction's paid_at. Stored so
     *                                        the P&L can detect cross-month
     *                                        refunds without joining back to
     *                                        the transactions table.
     */
    public function recordRefundedTransaction(
        Transaction $refundTransaction,
        ?string $originalPaidAt = null
    ): ?TransactionCommission {
        $gross = abs((float) $refundTransaction->total_amount);
        if ($gross <= 0.0) {
            return null;
        }

        $provider = strtolower((string) $refundTransaction->payment_provider);

        $event = $refundTransaction->event;
        $organization = $event?->organization;
        $platformPercent = $organization?->commission_percentage !== null
            ? (float) $organization->commission_percentage
            : Dataset::merchantCommissionPercent();

        $markupAmount = (float) ($refundTransaction->markup_amount ?? 0);
        $taxAndFees = (float) ($refundTransaction->tax_amount ?? 0);

        try {
            return TransactionCommission::firstOrCreate(
                [
                    'accountable_type' => Transaction::class,
                    'accountable_id' => $refundTransaction->uuid,
                    'transaction_type' => TransactionCommission::TYPE['REFUND'],
                ],
                [
                    'transaction_uuid' => $refundTransaction->uuid,
                    'event_uuid' => $refundTransaction->event_uuid,
                    'organization_uuid' => $refundTransaction->organization_uuid ?? $event?->organization_uuid,
                    'agent_uuid' => null,
                    'gross_amount' => $gross,
                    'markup_amount' => $markupAmount,
                    'tax_and_fees' => $taxAndFees,
                    'net_amount' => 0,
                    'ticketoc_commission_percent' => $platformPercent,
                    'ticketoc_commission' => 0,
                    'ticketoc_net_commission' => 0,
                    'agent_commission_percent' => 0,
                    'agent_commission' => 0,
                    'payment_provider' => $provider !== '' ? $provider : 'refund',
                    'payment_method' => null,
                    'payment_id' => $refundTransaction->payment_id,
                    'payment_gateway_commission_percent' => 0,
                    'payment_gateway_fixed_fee' => 0,
                    'payment_gateway_commission' => 0,
                    'currency' => 'PHP',
                    'date_paid' => $refundTransaction->updated_at ?? now(),
                    'original_paid_at' => $originalPaidAt,
                    'metadata' => [
                        'refund_for' => $refundTransaction->uuid,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Refund commission recording failed', [
                'transaction_uuid' => $refundTransaction->uuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAttributes(Transaction $transaction, float $gross, string $provider): array
    {
        $event = $transaction->event;
        $organization = $event?->organization;

        $platformPercent = $organization?->commission_percentage !== null
            ? (float) $organization->commission_percentage
            : Dataset::merchantCommissionPercent();

        $platformCommission = round($gross * ($platformPercent / 100.0), 2);

        $agentPercent = 0.0;
        $agentCommission = 0.0;
        if ($transaction->affiliate_partner_uuid && $event && $event->affiliate_commission_percent !== null) {
            $agentPercent = (float) $event->affiliate_commission_percent;
            $agentCommission = round($gross * ($agentPercent / 100.0), 2);
        }

        $paymongoRates = Dataset::paymongoRates();
        $paypalRates = Dataset::paypalRates();

        [$gatewayPercentRate, $gatewayFixed, $gatewayCommission, $methodKey] = $this->resolveGatewayBreakdown(
            $transaction,
            $provider,
            $gross,
            $paymongoRates,
            $paypalRates
        );

        // Organizer's net payable for this txn.
        $netAmount = round($gross - $platformCommission, 2);
        // What TicketOC actually keeps after paying the affiliate and the gateway.
        $ticketocNetCommission = round(
            $platformCommission - $agentCommission - $gatewayFixed - $gatewayCommission,
            2
        );

        return [
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'organization_uuid' => $transaction->organization_uuid ?? $event?->organization_uuid,
            'agent_uuid' => $transaction->affiliate_partner_uuid,
            'gross_amount' => $gross,
            'markup_amount' => round((float) ($transaction->markup_amount ?? 0), 2),
            'tax_and_fees' => round((float) ($transaction->tax_amount ?? 0), 2),
            'net_amount' => $netAmount,
            'ticketoc_commission_percent' => $platformPercent,
            'ticketoc_commission' => $platformCommission,
            'ticketoc_net_commission' => $ticketocNetCommission,
            'agent_commission_percent' => $agentPercent,
            'agent_commission' => $agentCommission,
            'payment_provider' => $provider,
            'payment_method' => $methodKey,
            'payment_id' => $transaction->payment_id,
            'payment_gateway_commission_percent' => $gatewayPercentRate,
            'payment_gateway_fixed_fee' => $gatewayFixed,
            'payment_gateway_commission' => $gatewayCommission,
            'currency' => $this->resolveCurrency($transaction),
            'date_paid' => $transaction->paid_at ?? now(),
            'metadata' => [
                'paymongo_rates' => $paymongoRates,
                'paypal_rates' => $paypalRates,
                'organization_commission_source' => $organization?->commission_percentage !== null
                    ? 'organization.commission_percentage'
                    : 'datasets.merchant_commission_percentage',
            ],
        ];
    }

    /**
     * Returns [percentRate, fixedFee, percentAmount, methodKey] for the gateway
     * used by this transaction. The amount columns are disjoint:
     *
     *   total_gateway_fee = percentAmount + fixedFee
     *
     * methodKey is null for PayPal (no per-method split).
     *
     * @param  array<string, float|null>  $paymongoRates  keyed by dataset name (e.g. 'qrph', 'card', 'gcash', 'dob', 'brankas')
     * @param  array<string, float|null>  $paypalRates    keyed by dataset name (e.g. 'paypal_fee', 'additional_fee')
     * @return array{0: float, 1: float, 2: float, 3: ?string}
     */
    private function resolveGatewayBreakdown(
        Transaction $transaction,
        string $provider,
        float $gross,
        array $paymongoRates,
        array $paypalRates
    ): array {
        if ($provider === 'paypal') {
            $percentRate = (float) ($paypalRates['paypal_fee'] ?? 0.0);
            $fixed = (float) ($paypalRates['additional_fee'] ?? 0.0);
            $percentAmount = round($gross * ($percentRate / 100.0), 2);

            return [$percentRate, $fixed, $percentAmount, null];
        }

        if ($provider === 'paymongo') {
            $methodKey = $this->resolvePaymongoMethodKey($transaction);
            if ($methodKey === null) {
                return [0.0, 0.0, 0.0, null];
            }

            $percentRate = (float) ($paymongoRates[$methodKey] ?? 0.0);
            $percentAmount = round($gross * ($percentRate / 100.0), 2);

            // DOB has a fixed minimum: the gateway charges max(percent, minimum).
            // We model this by promoting the higher of the two into the
            // commission/fixed columns so commission + fixed always equals what
            // was actually charged.
            if ($methodKey === 'dob') {
                $minimum = (float) ($paymongoRates['dob_fixed_minimum'] ?? 0.0);
                if ($minimum > $percentAmount) {
                    return [$percentRate, $minimum, 0.0, $methodKey];
                }
            }

            return [$percentRate, 0.0, $percentAmount, $methodKey];
        }

        return [0.0, 0.0, 0.0, null];
    }

    /**
     * Resolve the PayMongo method (mapped to the dataset key used in
     * `paymongo_payment_rates`). Tries paths in this order, taking the first
     * non-empty match:
     *
     *   1. data.attributes.payment_method_used   (most authoritative)
     *   2. data.attributes.payments[0].attributes.source.type   (legacy)
     *   3. data.attributes.source.type                          (legacy)
     */
    private function resolvePaymongoMethodKey(Transaction $transaction): ?string
    {
        $data = $transaction->payment_data;
        if (! is_array($data)) {
            return null;
        }

        $candidates = [
            data_get($data, 'data.attributes.payment_method_used'),
            data_get($data, 'data.attributes.payments.0.attributes.source.type'),
            data_get($data, 'data.attributes.source.type'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                $key = $this->normalizeMethodToDatasetKey($value);
                if ($key !== null) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Map a raw PayMongo `payment_method_used` / `source.type` value to the
     * dataset key used in the `paymongo_payment_rates` row (per DatasetSeeder):
     * qrph, card, gcash, grab_pay, shopee_pay, billease, paymaya, dob, brankas.
     *
     * Note: this preserves `qrph` (matching the seeder), which differs from
     * `PlatformPnLGatewayFeeEstimator`'s P&L bucketing convention (`qr_ph`).
     */
    private function normalizeMethodToDatasetKey(string $value): ?string
    {
        $v = strtolower(trim($value));

        return match ($v) {
            'qrph', 'qr_ph' => 'qrph',
            'card' => 'card',
            'gcash' => 'gcash',
            'grab_pay' => 'grab_pay',
            'shopee_pay' => 'shopee_pay',
            'billease' => 'billease',
            'paymaya' => 'paymaya',
            'dob', 'dob_ubp', 'direct_debit' => 'dob',
            'brankas', 'brankas_bdo', 'brankas_landbank', 'brankas_metrobank' => 'brankas',
            default => null,
        };
    }

    private function resolveCurrency(Transaction $transaction): string
    {
        $data = $transaction->payment_data;
        if (is_array($data)) {
            $currency = data_get($data, 'currency')
                ?? data_get($data, 'data.attributes.currency')
                ?? data_get($data, 'purchase_units.0.amount.currency_code');
            if (is_string($currency) && $currency !== '') {
                return strtoupper($currency);
            }
        }

        return 'PHP';
    }
}
