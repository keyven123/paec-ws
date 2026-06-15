<?php

namespace App\Models;

use App\Support\OrganizationPaymentMethods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Dataset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'value',
        'description',
    ];

    const DATA = [
        'name',
        'value',
        'description',
    ];

    public static function merchantCommissionPercent(): float
    {
        return (float) (static::where('name', 'merchant_commission_percentage')->value('value') ?? 0);
    }

    /**
     * Default checkout payment methods for new merchant organizations.
     *
     * @return list<array{name: string, value: bool, provider: string}>
     */
    public static function defaultPaymentMethods(): array
    {
        $raw = static::where('name', 'default_payment_methods')->value('value');
        if (! $raw) {
            return OrganizationPaymentMethods::defaults();
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return OrganizationPaymentMethods::defaults();
        }

        return OrganizationPaymentMethods::normalize($decoded);
    }

    /**
     * Returns paymongo rates keyed by method name, e.g. ['qr_ph' => 1.34, 'card' => 2.9, ...].
     *
     * @return array<string, float|null>
     */
    public static function paymongoRates(): array
    {
        $raw = static::where('name', 'paymongo_payment_rates')->value('value');
        if (! $raw) {
            return [];
        }
        $items = json_decode($raw, true);
        if (! is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            $result[(string) $item['name']] = isset($item['value']) ? (float) $item['value'] : null;
        }

        return $result;
    }

    /**
     * Returns paypal rates keyed by field name, e.g. ['paypal_fee' => 3.9, 'additional_fee' => 15.0].
     *
     * @return array<string, float|null>
     */
    /**
     * Default activity compliance rows provisioned for each new event.
     *
     * @return list<array{label: string, percentage: float, amount_type: string, status: string, fixed_amount?: float|null}>
     */
    public static function activityComplianceDefaults(): array
    {
        $raw = static::where('name', 'activity_compliance')->value('value');
        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn (array $item) => static::normalizeActivityComplianceTemplate($item))
            ->filter(fn (array $item) => $item['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{label: string, percentage: float, amount_type: string, status: string, fixed_amount: float|null}
     */
    private static function normalizeActivityComplianceTemplate(array $item): array
    {
        $percentage = $item['percentage']
            ?? $item['tax']
            ?? $item['city_tax']
            ?? $item['service_charge']
            ?? $item['value']
            ?? 0;

        return [
            'label' => (string) ($item['label'] ?? ''),
            'percentage' => (float) $percentage,
            'fixed_amount' => isset($item['fixed_amount']) ? (float) $item['fixed_amount'] : null,
            'amount_type' => (string) ($item['amount_type'] ?? 'percentage'),
            'status' => (string) ($item['status'] ?? 'inactive'),
        ];
    }

    public static function paypalRates(): array
    {
        $raw = static::where('name', 'paypal_payment_rates')->value('value');
        if (! $raw) {
            return [];
        }
        $items = json_decode($raw, true);
        if (! is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            $result[(string) $item['name']] = isset($item['value']) ? (float) $item['value'] : null;
        }

        return $result;
    }
}
