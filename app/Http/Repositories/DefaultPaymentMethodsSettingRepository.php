<?php

namespace App\Http\Repositories;

use App\Models\Dataset;
use App\Support\OrganizationPaymentMethods;

class DefaultPaymentMethodsSettingRepository
{
    public function getSingleton(): Dataset
    {
        return Dataset::firstOrCreate(
            ['name' => 'default_payment_methods'],
            [
                'description' => 'The default payment methods',
                'value' => json_encode(OrganizationPaymentMethods::defaults()),
            ]
        );
    }

    /**
     * @param array<int, array{name: string, value: bool, provider?: string}> $paymentMethods
     */
    public function updateSingleton(array $paymentMethods): Dataset
    {
        $row = $this->getSingleton();
        $normalized = OrganizationPaymentMethods::normalize($paymentMethods);
        $row->value = json_encode($normalized);
        $row->save();

        return $row;
    }

    /**
     * @return list<array{name: string, value: bool, provider: string}>
     */
    public function getNormalizedMethods(): array
    {
        return Dataset::defaultPaymentMethods();
    }
}
