<?php

namespace App\Http\Requests\Admin;

use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDefaultPaymentMethodsSettingRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*.name' => [
                'required',
                'string',
                Rule::in(OrganizationPaymentMethods::allKeys()),
            ],
            'payment_methods.*.value' => ['required', 'boolean'],
            'payment_methods.*.provider' => ['nullable', 'string', Rule::in(['paypal', 'paymongo'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_methods.required' => 'Payment methods are required.',
            'payment_methods.*.name.in' => 'One or more payment method names are invalid.',
            'payment_methods.*.value.boolean' => 'Each payment method value must be true or false.',
        ];
    }
}
