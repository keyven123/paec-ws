<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentGatewayRateSettingRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    /**
     * Frontend simple Paymongo keys (qrph instead of qr_ph; dob handled separately).
     */
    private const SIMPLE_METHODS = [
        'qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay', 'billease', 'paymaya', 'brankas',
    ];

    public function rules(): array
    {
        $rules = [
            'paymongo'                   => ['required', 'array'],
            'paymongo.dob'               => ['nullable', 'array'],
            'paymongo.dob.percentage'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paymongo.dob.fixed_minimum' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'paypal'                     => ['required', 'array'],
            'paypal.paypal_fee'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paypal.additional_fee'      => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
        ];

        foreach (self::SIMPLE_METHODS as $method) {
            $rules['paymongo.' . $method] = ['nullable', 'numeric', 'min:0', 'max:100'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paymongo.required' => 'Paymongo rates payload is required.',
            'paypal.required'   => 'PayPal rates payload is required.',
        ];
    }
}
