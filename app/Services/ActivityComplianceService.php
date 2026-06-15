<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\Transaction;
use App\Models\TransactionCompliance;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ActivityComplianceService
{
    /**
     * @return list<array{label: string, percentage: float, amount_type: string, status: string, fixed_amount?: float|null}>
     */
    public static function defaultTemplates(): array
    {
        return Dataset::activityComplianceDefaults();
    }

    public static function provisionDefaultsForEvent(Event $event): void
    {
        foreach (self::defaultTemplates() as $template) {
            ActivityCompliance::query()->firstOrCreate(
                [
                    'activityable_type' => 'event',
                    'activityable_id' => $event->uuid,
                    'label' => $template['label'],
                ],
                [
                    'percentage' => $template['percentage'],
                    'fixed_amount' => $template['fixed_amount'] ?? null,
                    'amount_type' => $template['amount_type'],
                    'status' => $template['status'],
                ],
            );
        }
    }

    /**
     * @return array{
     *   tax_amount: float,
     *   lines: list<array{activity_compliance_uuid: string, label: string, percentage: float, amount_type: string, amount: float}>,
     *   included_note: string|null,
     *   active_percentage_total: float
     * }
     */
    public static function calculateForEvent(
        Event $event,
        float $netSubtotal,
        bool $percentageRulesOnly = false,
        string $appliesTo = TransactionCompliance::APPLIES_TO_MERCHANDISE,
    ): array {
        $rules = self::activeRulesForEvent($event);
        if ($percentageRulesOnly) {
            $rules = $rules->where('amount_type', ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']);
        }

        $lines = [];
        $taxAmount = 0.0;

        foreach ($rules as $rule) {
            $amount = self::lineAmount($rule, $netSubtotal);
            $taxAmount += $amount;
            $lines[] = [
                'activity_compliance_uuid' => $rule->uuid,
                'label' => $rule->label,
                'percentage' => (float) $rule->percentage,
                'amount_type' => $rule->amount_type,
                'amount' => $amount,
                'applies_to' => $appliesTo,
            ];
        }

        return [
            'tax_amount' => round($taxAmount, 2),
            'lines' => $lines,
            'included_note' => $percentageRulesOnly ? null : self::buildIncludedNote($rules),
            'active_percentage_total' => self::sumActivePercentage($rules),
        ];
    }

    /**
     * @param  array{sub_total: float, discount: float, promo_code_discount?: float|null, markup_amount?: float|null}  $amounts
     * @return array{tax_amount: float, total_amount: float, included_note: string|null, compliance_lines: list<array>}
     */
    public static function applyToCheckoutAmounts(Event $event, array $amounts): array
    {
        $promoDiscount = (float) ($amounts['promo_code_discount'] ?? 0);
        $markupNet = max(0.0, round((float) ($amounts['markup_amount'] ?? 0), 2));

        $merchNet = max(
            0.0,
            round((float) $amounts['sub_total'] - (float) $amounts['discount'] - $promoDiscount, 2),
        );

        $merchCompliance = self::calculateForEvent(
            $event,
            $merchNet,
            false,
            TransactionCompliance::APPLIES_TO_MERCHANDISE,
        );

        $markupCompliance = self::calculateForEvent(
            $event,
            $markupNet,
            true,
            TransactionCompliance::APPLIES_TO_MARKUP,
        );

        $taxAmount = round($merchCompliance['tax_amount'] + $markupCompliance['tax_amount'], 2);
        $complianceLines = array_merge($merchCompliance['lines'], $markupCompliance['lines']);

        return [
            'tax_amount' => $taxAmount,
            'total_amount' => round($merchNet + $markupNet + $taxAmount, 2),
            'included_note' => $merchCompliance['included_note'],
            'compliance_lines' => $complianceLines,
        ];
    }

    /**
     * @param  list<array{activity_compliance_uuid: string, label: string, percentage: float, amount_type: string, amount: float, applies_to?: string}>  $lines
     */
    public static function recordForTransaction(Transaction $transaction, array $lines): void
    {
        foreach ($lines as $line) {
            $appliesTo = $line['applies_to'] ?? TransactionCompliance::APPLIES_TO_MERCHANDISE;

            TransactionCompliance::query()->firstOrCreate(
                [
                    'transaction_uuid' => $transaction->uuid,
                    'activity_compliance_uuid' => $line['activity_compliance_uuid'],
                    'applies_to' => $appliesTo,
                ],
                [
                    'percentage' => $line['percentage'],
                    'amount' => $line['amount'],
                ],
            );
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertActivePercentageTotalWithinLimit(Event $event, ?string $excludingUuid = null): void
    {
        $rules = self::activeRulesForEvent($event);
        if ($excludingUuid) {
            $rules = $rules->reject(fn (ActivityCompliance $rule) => $rule->uuid === $excludingUuid);
        }

        if (self::sumActivePercentage($rules) > 100) {
            throw ValidationException::withMessages([
                'activity_compliances' => ['Active compliance percentages cannot exceed 100% in total.'],
            ]);
        }
    }

    /**
     * Validate stacked percentage using the candidate row's in-memory state (before save).
     *
     * @throws ValidationException
     */
    public static function assertCandidateActivePercentageTotal(Event $event, ActivityCompliance $candidate): void
    {
        $rows = ActivityCompliance::query()
            ->where('activityable_type', 'event')
            ->where('activityable_id', $event->uuid)
            ->get();

        $total = 0.0;
        foreach ($rows as $row) {
            if ($candidate->uuid && $row->uuid === $candidate->uuid) {
                continue;
            }
            if ($row->status !== GeneralConstants::GENERAL_STATUSES['ACTIVE']) {
                continue;
            }
            if ($row->amount_type !== ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']) {
                continue;
            }
            $total += (float) $row->percentage;
        }

        if (
            $candidate->status === GeneralConstants::GENERAL_STATUSES['ACTIVE']
            && $candidate->amount_type === ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']
        ) {
            $total += (float) $candidate->percentage;
        }

        if ($total > 100) {
            throw ValidationException::withMessages([
                'percentage' => ['Active compliance percentages cannot exceed 100% in total.'],
            ]);
        }
    }

    /**
     * @return Collection<int, ActivityCompliance>
     */
    public static function activeRulesForEvent(Event $event): Collection
    {
        return ActivityCompliance::query()
            ->where('activityable_type', 'event')
            ->where('activityable_id', $event->uuid)
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->orderBy('label')
            ->get();
    }

    /**
     * @param  Collection<int, ActivityCompliance>  $rules
     */
    public static function sumActivePercentage(Collection $rules): float
    {
        return (float) $rules
            ->where('amount_type', ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'])
            ->sum(fn (ActivityCompliance $rule) => (float) $rule->percentage);
    }

    private static function lineAmount(ActivityCompliance $rule, float $netSubtotal): float
    {
        if ($rule->amount_type === ActivityCompliance::AMOUNT_TYPE['FIXED']) {
            return round((float) ($rule->fixed_amount ?? 0), 2);
        }

        return round($netSubtotal * ((float) $rule->percentage / 100), 2);
    }

    /**
     * @param  Collection<int, \App\Models\TransactionCompliance>  $snapshots
     */
    public static function buildIncludedNoteFromSnapshots(Collection $snapshots): ?string
    {
        if ($snapshots->isEmpty()) {
            return null;
        }

        // Same rule may have separate snapshot rows (merchandise vs markup); show each label once.
        $parts = $snapshots
            ->unique(fn ($row) => $row->activity_compliance_uuid ?? $row->uuid)
            ->sortBy(fn ($row) => $row->activityCompliance?->label ?? '')
            ->map(function ($row) {
                $rule = $row->activityCompliance;
                if ($rule) {
                    return self::formatComplianceForIncludedNote(
                        $rule->label,
                        $rule->amount_type,
                        (float) $rule->percentage,
                        $rule->fixed_amount !== null ? (float) $rule->fixed_amount : null,
                    );
                }

                $pct = (float) $row->percentage;

                if ($pct > 0) {
                    return self::formatComplianceForIncludedNote(
                        'Fee',
                        ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
                        $pct,
                    );
                }

                return self::formatComplianceForIncludedNote(
                    'Fee',
                    ActivityCompliance::AMOUNT_TYPE['FIXED'],
                    0,
                    (float) $row->amount,
                );
            })
            ->all();

        return 'Included in price: '.implode(', ', $parts);
    }

    public static function formatComplianceForIncludedNote(
        string $label,
        string $amountType,
        float $percentage,
        ?float $fixedAmount = null,
    ): string {
        if ($amountType === ActivityCompliance::AMOUNT_TYPE['FIXED']) {
            $amount = rtrim(rtrim(number_format((float) ($fixedAmount ?? 0), 2, '.', ''), '0'), '.');

            return "{$label} {$amount}";
        }

        $pct = rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.');

        return "{$label} {$pct}%";
    }

    /**
     * @param  Collection<int, ActivityCompliance>  $activeRules
     */
    private static function buildIncludedNote(Collection $activeRules): ?string
    {
        if ($activeRules->isEmpty()) {
            return null;
        }

        $parts = $activeRules
            ->map(fn (ActivityCompliance $rule) => self::formatComplianceForIncludedNote(
                $rule->label,
                $rule->amount_type,
                (float) $rule->percentage,
                $rule->fixed_amount !== null ? (float) $rule->fixed_amount : null,
            ))
            ->all();

        return 'Included in price: '.implode(', ', $parts);
    }
}
