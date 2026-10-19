<?php

namespace App\Support;

/**
 * Normalizes payment-channel deduction rules used by Finance settings.
 *
 * fee_mode:
 * - percentage — % of gross only
 * - fixed — flat PHP fee only
 * - higher_of — max(% of gross, fixed fee)
 * - percentage_plus_fixed — % of gross + fixed fee
 */
final class PaymentChannelFee
{
    public const MODE_PERCENTAGE = 'percentage';

    public const MODE_FIXED = 'fixed';

    public const MODE_HIGHER_OF = 'higher_of';

    public const MODE_PERCENTAGE_PLUS_FIXED = 'percentage_plus_fixed';

    public const MODES = [
        self::MODE_PERCENTAGE,
        self::MODE_FIXED,
        self::MODE_HIGHER_OF,
        self::MODE_PERCENTAGE_PLUS_FIXED,
    ];

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{fee_mode: string, percentage: float|null, fixed_fee: float|null, show_in_sales: bool}
     */
    public static function normalize(?array $raw, string $defaultMode = self::MODE_PERCENTAGE): array
    {
        $mode = is_string($raw['fee_mode'] ?? null) && in_array($raw['fee_mode'], self::MODES, true)
            ? $raw['fee_mode']
            : $defaultMode;

        $percentage = array_key_exists('percentage', $raw ?? []) && $raw['percentage'] !== null && $raw['percentage'] !== ''
            ? (float) $raw['percentage']
            : null;
        $fixed = array_key_exists('fixed_fee', $raw ?? []) && $raw['fixed_fee'] !== null && $raw['fixed_fee'] !== ''
            ? (float) $raw['fixed_fee']
            : null;

        // Default checked so newly configured channels appear in Sales summary.
        $showInSales = true;
        if (is_array($raw) && array_key_exists('show_in_sales', $raw)) {
            $showInSales = filter_var($raw['show_in_sales'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($showInSales === null) {
                $showInSales = (bool) $raw['show_in_sales'];
            }
        }

        return [
            'fee_mode' => $mode,
            'percentage' => $percentage,
            'fixed_fee' => $fixed,
            'show_in_sales' => $showInSales,
        ];
    }

    /**
     * @param  array{fee_mode?: string, percentage?: float|null, fixed_fee?: float|null}  $fee
     * @return array{0: float, 1: float, 2: float} percentRate, fixedStored, percentAmount
     */
    public static function breakdown(array $fee, float $gross): array
    {
        $mode = $fee['fee_mode'] ?? self::MODE_PERCENTAGE;
        $pct = (float) ($fee['percentage'] ?? 0);
        $fixed = (float) ($fee['fixed_fee'] ?? 0);
        $fromPct = round($gross * ($pct / 100.0), 2);

        return match ($mode) {
            self::MODE_FIXED => [0.0, $fixed, 0.0],
            self::MODE_HIGHER_OF => $fixed > $fromPct
                ? [$pct, $fixed, 0.0]
                : [$pct, 0.0, $fromPct],
            self::MODE_PERCENTAGE_PLUS_FIXED => [$pct, $fixed, $fromPct],
            default => [$pct, 0.0, $fromPct],
        };
    }

    /**
     * Estimate total gateway fee for a gross amount.
     *
     * @param  array{fee_mode?: string, percentage?: float|null, fixed_fee?: float|null}  $fee
     */
    public static function estimate(array $fee, float $gross): float
    {
        [$percentRate, $fixedStored, $percentAmount] = self::breakdown($fee, $gross);

        return round($percentAmount + $fixedStored, 2);
    }
}
