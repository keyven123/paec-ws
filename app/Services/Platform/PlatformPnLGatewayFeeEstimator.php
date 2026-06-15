<?php

namespace App\Services\Platform;

use App\Models\Transaction;

/**
 * Estimates payment-gateway fees using rate arrays loaded from the datasets table.
 *
 * @param array<string, float|null> $paymongoRates  keyed by method name (e.g. 'qr_ph', 'card', 'dob', …)
 * @param array<string, float|null> $paypalRates    keyed by field name  (e.g. 'paypal_fee', 'additional_fee')
 */
class PlatformPnLGatewayFeeEstimator
{
    /**
     * Resolved PayMongo method for P&L bucketing (matches dataset keys: qr_ph, card, dob, brankas, …).
     * Returns null when provider is not PayMongo or method cannot be read from payment_data.
     */
    public function paymongoMethodBucketForTransaction(Transaction $transaction): ?string
    {
        if (strtolower((string) $transaction->payment_provider) !== 'paymongo') {
            return null;
        }

        $data = $transaction->payment_data;
        if (! is_array($data)) {
            return null;
        }

        return $this->resolvePaymongoMethodKey($data);
    }

    /**
     * @param  array<string, float|null>  $paymongoRates
     * @param  array<string, float|null>  $paypalRates
     * @param  array<string, mixed>|null  $paymentData
     */
    public function estimate(
        Transaction $transaction,
        array $paymongoRates,
        array $paypalRates,
        ?array $paymentData = null
    ): float {
        $gross = (float) $transaction->total_amount;
        if ($gross <= 0.0) {
            return 0.0;
        }

        $provider = strtolower((string) $transaction->payment_provider);

        if ($provider === '' || $provider === 'free') {
            return 0.0;
        }

        if ($provider === 'paypal') {
            $pct = (float) ($paypalRates['paypal_fee'] ?? 0.0);
            $fixed = (float) ($paypalRates['additional_fee'] ?? 0.0);

            return round($gross * ($pct / 100.0) + $fixed, 2);
        }

        if ($provider === 'paymongo') {
            $data = $paymentData ?? $transaction->payment_data;
            if (! is_array($data)) {
                return $this->fallbackPaymongoFee($gross, $paymongoRates);
            }

            $method = $this->resolvePaymongoMethodKey($data);
            if ($method === null) {
                return $this->fallbackPaymongoFee($gross, $paymongoRates);
            }

            if ($method === 'dob') {
                $pct = $paymongoRates['dob'] ?? null;
                $min = $paymongoRates['dob_fixed_minimum'] ?? null;
                if ($pct === null) {
                    return $this->fallbackPaymongoFee($gross, $paymongoRates);
                }
                $fromPct = round($gross * ((float) $pct / 100.0), 2);

                return $min !== null ? max($fromPct, (float) $min) : $fromPct;
            }

            $pct = $paymongoRates[$method] ?? null;
            if ($pct === null) {
                return $this->fallbackPaymongoFee($gross, $paymongoRates);
            }

            return round($gross * ((float) $pct / 100.0), 2);
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $paymentData
     */
    private function resolvePaymongoMethodKey(array $paymentData): ?string
    {
        $payments = data_get($paymentData, 'data.attributes.payments');
        if (is_array($payments) && isset($payments[0]) && is_array($payments[0])) {
            $attrs = $payments[0]['attributes'] ?? null;
            if (is_array($attrs)) {
                $source = $attrs['source'] ?? null;
                if (is_array($source) && isset($source['type'])) {
                    return $this->normalizePaymongoMethod((string) $source['type']);
                }
            }
        }

        $nestedType = data_get($paymentData, 'data.attributes.source.type');
        if (is_string($nestedType) && $nestedType !== '') {
            return $this->normalizePaymongoMethod($nestedType);
        }

        return null;
    }

    private function normalizePaymongoMethod(string $type): ?string
    {
        $t = strtolower(trim($type));

        return match ($t) {
            'qrph' => 'qr_ph',
            'card' => 'card',
            'gcash' => 'gcash',
            'grab_pay' => 'grab_pay',
            'shopee_pay' => 'shopee_pay',
            'billease' => 'billease',
            'paymaya' => 'paymaya',
            'dob', 'dob_ubp', 'direct_debit' => 'dob',
            'brankas_bdo', 'brankas_landbank', 'brankas_metrobank', 'brankas' => 'brankas',
            default => null,
        };
    }

    /**
     * @param  array<string, float|null>  $paymongoRates
     */
    private function fallbackPaymongoFee(float $gross, array $paymongoRates): float
    {
        // Exclude dob_fixed_minimum — it is a fixed amount, not a percentage.
        $percentageOnly = array_diff_key($paymongoRates, ['dob_fixed_minimum' => true]);
        $values = array_filter(array_values($percentageOnly), fn ($v) => $v !== null);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }

        $avgPct = array_sum($values) / $n;

        return round($gross * ($avgPct / 100.0), 2);
    }
}
