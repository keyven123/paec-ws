<?php

namespace App\Http\Repositories;

use App\Models\Dataset;
use App\Services\Payments\PaynamicsService;
use App\Support\PaymentChannelFee;

class PaymentGatewayRateSettingRepository
{
    public const PAYMONGO_METHODS = [
        'qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay',
        'billease', 'paymaya', 'dob', 'dob_fixed_minimum', 'brankas',
    ];

    public const PAYMONGO_CHANNEL_KEYS = [
        'qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay',
        'billease', 'paymaya', 'dob', 'brankas',
    ];

    public const PAYPAL_FIELDS = ['paypal_fee', 'additional_fee'];

    public const PAYNAMICS_CHANNELS = [
        'card', 'gcash', 'maya', 'grabpay', 'shopeepay',
        'bdo', 'bpi', 'landbank', 'unionbank', 'billease',
    ];

    /** Default Paynamics fees aligned to the merchant rate sheet. */
    public const PAYNAMICS_DEFAULTS = [
        'bpi' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.0, 'fixed_fee' => 20.0],
        'unionbank' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.0, 'fixed_fee' => 20.0],
        'bdo' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.0, 'fixed_fee' => 20.0],
        'gcash' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.5, 'fixed_fee' => 20.0],
        'grabpay' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.0, 'fixed_fee' => 25.0],
        'maya' => ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => 2.5, 'fixed_fee' => 25.0],
    ];

    public function getPaymongoDataset(): Dataset
    {
        return Dataset::firstOrCreate(
            ['name' => 'paymongo_payment_rates'],
            [
                'description' => 'The default paymongo payment gateway rates',
                'value' => json_encode([]),
            ]
        );
    }

    public function getPaypalDataset(): Dataset
    {
        return Dataset::firstOrCreate(
            ['name' => 'paypal_payment_rates'],
            [
                'description' => 'The default paypal payment gateway rates',
                'value' => json_encode([]),
            ]
        );
    }

    public function getPaynamicsDataset(): Dataset
    {
        $defaults = [];
        foreach (self::PAYNAMICS_CHANNELS as $channel) {
            $base = self::PAYNAMICS_DEFAULTS[$channel]
                ?? ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => null, 'fixed_fee' => null];
            $base['show_in_sales'] = true;
            $defaults[] = [
                'name' => $channel,
                'value' => $base,
            ];
        }

        return Dataset::firstOrCreate(
            ['name' => 'paynamics_payment_rates'],
            [
                'description' => 'Paynamics payment channel deduction rates for Finance',
                'value' => json_encode($defaults),
            ]
        );
    }

    public function getVisibilityDataset(): Dataset
    {
        return Dataset::firstOrCreate(
            ['name' => 'finance_channel_sales_visibility'],
            [
                'description' => 'Which payment channels appear in Finance Sales summary',
                'value' => json_encode(new \stdClass),
            ]
        );
    }

    /**
     * Visibility map keyed by "provider.channel" => bool.
     *
     * @return array<string, bool>
     */
    public function getSalesVisibility(): array
    {
        $raw = $this->getVisibilityDataset()->value;
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = (bool) $value;
        }

        return $result;
    }

    /**
     * @param  array<string, bool>  $visibility
     */
    public function updateSalesVisibility(array $visibility): Dataset
    {
        $row = $this->getVisibilityDataset();
        $current = $this->getSalesVisibility();
        foreach ($visibility as $key => $value) {
            $current[(string) $key] = (bool) $value;
        }
        $row->value = json_encode($current);
        $row->save();

        return $row;
    }

    public function isChannelVisibleInSales(string $provider, string $channel): bool
    {
        $map = $this->getSalesVisibility();
        $key = $provider.'.'.$channel;
        if (array_key_exists($key, $map)) {
            return $map[$key];
        }

        // Paynamics stores show_in_sales on the fee object.
        if ($provider === 'paynamics') {
            $rates = \App\Models\Dataset::paynamicsRates();
            if (isset($rates[$channel]['show_in_sales'])) {
                return (bool) $rates[$channel]['show_in_sales'];
            }
        }

        return true;
    }

    public function updatePaymongoRates(array $rates): Dataset
    {
        $row = $this->getPaymongoDataset();
        $current = Dataset::paymongoRates();
        foreach ($rates as $key => $value) {
            if (in_array($key, self::PAYMONGO_METHODS, true)) {
                $current[$key] = $value;
            }
        }
        $items = array_map(
            fn ($name) => ['name' => $name, 'value' => $current[$name] ?? null],
            self::PAYMONGO_METHODS
        );
        $row->value = json_encode($items);
        $row->save();

        return $row;
    }

    public function updatePaypalRates(array $rates): Dataset
    {
        $row = $this->getPaypalDataset();
        $current = Dataset::paypalRates();
        foreach ($rates as $key => $value) {
            if (in_array($key, self::PAYPAL_FIELDS, true)) {
                $current[$key] = $value;
            }
        }
        $items = array_map(
            fn ($field) => ['name' => $field, 'value' => $current[$field] ?? null],
            self::PAYPAL_FIELDS
        );
        $row->value = json_encode($items);
        $row->save();

        return $row;
    }

    /**
     * @param  array<string, array{fee_mode?: string, percentage?: float|null, fixed_fee?: float|null}>  $rates
     */
    public function updatePaynamicsRates(array $rates): Dataset
    {
        $row = $this->getPaynamicsDataset();
        $current = Dataset::paynamicsRates();

        foreach ($rates as $channel => $fee) {
            if (! in_array($channel, self::PAYNAMICS_CHANNELS, true)) {
                continue;
            }
            $current[$channel] = PaymentChannelFee::normalize(
                is_array($fee) ? $fee : null,
                PaymentChannelFee::MODE_HIGHER_OF
            );
        }

        $items = [];
        foreach (self::PAYNAMICS_CHANNELS as $channel) {
            $items[] = [
                'name' => $channel,
                'value' => $current[$channel]
                    ?? array_merge(
                        self::PAYNAMICS_DEFAULTS[$channel]
                            ?? ['fee_mode' => PaymentChannelFee::MODE_HIGHER_OF, 'percentage' => null, 'fixed_fee' => null],
                        ['show_in_sales' => true]
                    ),
            ];
        }

        $row->value = json_encode($items);
        $row->save();

        return $row;
    }

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function paynamicsChannelCatalog(): array
    {
        $labels = [
            'card' => ['Credit / Debit Card', 'Card'],
            'gcash' => ['GCash', 'Wallets'],
            'maya' => ['Maya (PayMaya)', 'Wallets'],
            'grabpay' => ['GrabPay', 'Wallets'],
            'shopeepay' => ['ShopeePay', 'Wallets'],
            'bdo' => ['BDO Online Banking', 'Online Bank Transfers'],
            'bpi' => ['BPI Direct Debit', 'Online Bank Transfers'],
            'landbank' => ['Landbank', 'Online Bank Transfers'],
            'unionbank' => ['UnionBank Direct Debit', 'Online Bank Transfers'],
            'billease' => ['BillEase', 'Buy Now, Pay Later'],
        ];

        $catalog = [];
        foreach (array_keys(PaynamicsService::CHANNELS) as $key) {
            [$label, $group] = $labels[$key] ?? [strtoupper($key), 'Other'];
            $catalog[] = ['key' => $key, 'label' => $label, 'group' => $group];
        }

        return $catalog;
    }

    /**
     * @return list<array{key: string, label: string, group: string}>
     */
    public function paymongoChannelCatalog(): array
    {
        return [
            ['key' => 'qrph', 'label' => 'QRPH', 'group' => 'Other'],
            ['key' => 'card', 'label' => 'Card', 'group' => 'Card'],
            ['key' => 'gcash', 'label' => 'GCash', 'group' => 'Wallets'],
            ['key' => 'grab_pay', 'label' => 'GrabPay', 'group' => 'Wallets'],
            ['key' => 'shopee_pay', 'label' => 'ShopeePay', 'group' => 'Wallets'],
            ['key' => 'paymaya', 'label' => 'PayMaya', 'group' => 'Wallets'],
            ['key' => 'billease', 'label' => 'BillEase', 'group' => 'Buy Now, Pay Later'],
            ['key' => 'dob', 'label' => 'Direct Online Banking', 'group' => 'Online Bank Transfers'],
            ['key' => 'brankas', 'label' => 'Brankas', 'group' => 'Online Bank Transfers'],
        ];
    }
}
