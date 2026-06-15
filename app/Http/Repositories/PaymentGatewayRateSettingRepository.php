<?php

namespace App\Http\Repositories;

use App\Models\Dataset;

class PaymentGatewayRateSettingRepository
{
    public const PAYMONGO_METHODS = [
        'qr_ph', 'card', 'gcash', 'grab_pay', 'shopee_pay',
        'billease', 'paymaya', 'dob', 'dob_fixed_minimum', 'brankas',
    ];

    public const PAYPAL_FIELDS = ['paypal_fee', 'additional_fee'];

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

    public function updatePaymongoRates(array $rates): Dataset
    {
        $row = $this->getPaymongoDataset();
        $current = Dataset::paymongoRates();
        foreach ($rates as $key => $value) {
            if (in_array($key, self::PAYMONGO_METHODS, true)) {
                $current[$key] = $value;
            }
        }
        $items = array_map(fn ($name) => ['name' => $name, 'value' => $current[$name] ?? null], self::PAYMONGO_METHODS);
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
        $items = array_map(fn ($field) => ['name' => $field, 'value' => $current[$field] ?? null], self::PAYPAL_FIELDS);
        $row->value = json_encode($items);
        $row->save();

        return $row;
    }
}
