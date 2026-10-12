<?php

namespace App\Http\Requests\PaymentGateway;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentGatewayRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', Rule::in(array_values(GeneralConstants::PAYMENT_GATEWAY_PROVIDERS))],
            'environment' => [
                'nullable',
                Rule::in([...array_values(GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS), 'unverified']),
            ],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
