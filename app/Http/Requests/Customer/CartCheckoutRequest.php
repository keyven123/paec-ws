<?php

namespace App\Http\Requests\Customer;

use App\Services\Payments\PaynamicsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CartCheckoutRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_gateway_uuid' => ['nullable', 'uuid'],
            'payment_channel' => ['nullable', 'string', Rule::in(array_keys(PaynamicsService::CHANNELS))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_gateway_uuid.uuid' => 'Select a valid payment method.',
            'payment_channel.in' => 'Choose a valid Paynamics payment option.',
        ];
    }
}
