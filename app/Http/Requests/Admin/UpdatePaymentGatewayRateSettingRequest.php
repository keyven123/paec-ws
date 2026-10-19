<?php

namespace App\Http\Requests\Admin;

use App\Http\Repositories\PaymentGatewayRateSettingRepository;
use App\Support\PaymentChannelFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentGatewayRateSettingRequest extends FormRequest
{
    /**
     * Frontend simple Paymongo keys (qrph instead of qr_ph; dob handled separately).
     */
    private const SIMPLE_METHODS = [
        'qrph', 'card', 'gcash', 'grab_pay', 'shopee_pay', 'billease', 'paymaya', 'brankas',
    ];

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'paymongo' => ['required', 'array'],
            'paymongo.dob' => ['nullable', 'array'],
            'paymongo.dob.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paymongo.dob.fixed_minimum' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'paymongo.dob.fixed_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'paymongo.dob.fee_mode' => ['nullable', Rule::in(PaymentChannelFee::MODES)],
            'paypal' => ['required', 'array'],
            'paypal.paypal_fee' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paypal.additional_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999'],
            'paynamics' => ['nullable', 'array'],
            'paypal.show_in_sales' => ['nullable', 'boolean'],
            'sales_visibility' => ['nullable', 'array'],
            'sales_visibility.*' => ['boolean'],
        ];

        foreach (self::SIMPLE_METHODS as $method) {
            // Bare percentage number is validated in withValidator; object form allowed too.
            $rules['paymongo.'.$method] = ['nullable'];
        }

        foreach (PaymentGatewayRateSettingRepository::PAYNAMICS_CHANNELS as $channel) {
            $rules["paynamics.$channel"] = ['nullable', 'array'];
            $rules["paynamics.$channel.fee_mode"] = ['nullable', Rule::in(PaymentChannelFee::MODES)];
            $rules["paynamics.$channel.percentage"] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $rules["paynamics.$channel.fixed_fee"] = ['nullable', 'numeric', 'min:0', 'max:99999999.9999'];
            $rules["paynamics.$channel.show_in_sales"] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $paymongo = $this->input('paymongo', []);
            if (! is_array($paymongo)) {
                return;
            }

            foreach (self::SIMPLE_METHODS as $method) {
                if (! array_key_exists($method, $paymongo)) {
                    continue;
                }
                $value = $paymongo[$method];
                if ($value === null) {
                    continue;
                }
                if (is_numeric($value)) {
                    if ((float) $value < 0 || (float) $value > 100) {
                        $validator->errors()->add("paymongo.$method", 'The percentage must be between 0 and 100.');
                    }
                    continue;
                }
                if (! is_array($value)) {
                    $validator->errors()->add("paymongo.$method", 'Must be a percentage or fee object.');
                    continue;
                }
                if (isset($value['percentage']) && $value['percentage'] !== null
                    && (! is_numeric($value['percentage']) || (float) $value['percentage'] < 0 || (float) $value['percentage'] > 100)) {
                    $validator->errors()->add("paymongo.$method.percentage", 'The percentage must be between 0 and 100.');
                }
                if (isset($value['fixed_fee']) && $value['fixed_fee'] !== null
                    && (! is_numeric($value['fixed_fee']) || (float) $value['fixed_fee'] < 0)) {
                    $validator->errors()->add("paymongo.$method.fixed_fee", 'The fixed fee must be 0 or greater.');
                }
                if (isset($value['fee_mode']) && ! in_array($value['fee_mode'], PaymentChannelFee::MODES, true)) {
                    $validator->errors()->add("paymongo.$method.fee_mode", 'Invalid fee mode.');
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paymongo.required' => 'Paymongo rates payload is required.',
            'paypal.required' => 'PayPal rates payload is required.',
        ];
    }
}
