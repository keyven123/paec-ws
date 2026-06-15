<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Dataset;

class DatasetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Dataset::firstOrCreate(
            ['name' => 'site_visit'],
            [
                'description' => 'Total Visit on the website',
                'value' => '0',
            ],
        );

        Dataset::firstOrCreate(
            ['name' => 'merchant_commission_percentage'],
            [
                'description' => 'The default merchant commission percentage',
                'value' => '10',
            ],
        );

        Dataset::firstOrCreate(
            ['name' => 'paymongo_payment_rates'],
            [
                'description' => 'The default paymongo payment gateway rates',
                'value' => json_encode([
                    ['name' => 'qrph',      'value' => 1.34],
                    ['name' => 'card',       'value' => 2.9],
                    ['name' => 'gcash',      'value' => 2.23],
                    ['name' => 'grab_pay',   'value' => 1.96],
                    ['name' => 'shopee_pay', 'value' => 1.7],
                    ['name' => 'billease',   'value' => 1.34],
                    ['name' => 'paymaya',    'value' => 1.79],
                    ['name' => 'dob',               'value' => 1.29],
                    ['name' => 'dob_fixed_minimum', 'value' => 0],
                    ['name' => 'brankas',           'value' => 1.34],
                ]),
            ],
        );

        Dataset::firstOrCreate(
            ['name' => 'paypal_payment_rates'],
            [
                'description' => 'The default paypal payment gateway rates',
                'value' => json_encode([
                    ['name' => 'paypal_fee',     'value' => 3.9],
                    ['name' => 'additional_fee', 'value' => 15],
                ]),
            ],
        );

        Dataset::firstOrCreate(
            ['name' => 'activity_compliance'],
            [
                'description' => 'Compliance taxes and fees',
                'value' => json_encode([
                    [
                        'label' => 'VAT',
                        'percentage' => 12.00,
                        'amount_type' => 'percentage',
                        'status' => 'inactive',
                    ],
                    [
                        'label' => 'City Tax',
                        'percentage' => 0,
                        'amount_type' => 'percentage',
                        'status' => 'inactive',
                    ],
                    [
                        'label' => 'Service Charge',
                        'percentage' => 0,
                        'amount_type' => 'percentage',
                        'status' => 'inactive',
                    ],
                ]),
            ],
        );

        Dataset::firstOrCreate(
            ['name' => 'default_payment_methods'],
            [
                'description' => 'The default payment methods',
                'value' => json_encode([
                    ['name' => 'qrph', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'card', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'gcash', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'grab_pay', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'shopee_pay', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'billease', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'paymaya', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'dob', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'dob_fixed_minimum', 'value' => false, 'provider' => 'paymongo'],
                    ['name' => 'brankas', 'value' => true, 'provider' => 'paymongo'],
                    ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
                ]),
            ],
        );
    }
}
