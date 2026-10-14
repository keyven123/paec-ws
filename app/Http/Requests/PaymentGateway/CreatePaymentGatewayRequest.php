<?php

namespace App\Http\Requests\PaymentGateway;

use App\Constants\GeneralConstants;
use App\Services\Payments\PaynamicsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentGatewayRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::in(array_values(GeneralConstants::PAYMENT_GATEWAY_PROVIDERS))],
            'gateway_id' => [
                'required',
                'string',
                'max:255',
                Rule::unique('payment_gateways', 'gateway_id')->whereNull('deleted_at'),
            ],
            'public_key' => ['required', 'string', 'max:2000'],
            'secret_key' => ['required', 'string', 'max:2000'],
            'api_username' => ['exclude_unless:provider,paynamics', 'required', 'string', 'max:255'],
            'api_password' => ['exclude_unless:provider,paynamics', 'required', 'string', 'max:255'],
            'paybiz_wallet_id' => ['exclude_unless:provider,paynamics', 'nullable', 'string', 'max:64', 'regex:' . PaynamicsService::PAYBIZ_WALLET_ID_PATTERN],
            'payment_channels' => ['exclude_unless:provider,paynamics', 'required', 'array', 'min:1'],
            'payment_channels.*' => ['exclude_unless:provider,paynamics', 'distinct', Rule::in(array_keys(PaynamicsService::CHANNELS))],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gateway_id.unique' => 'A payment gateway with this ID already exists.',
            'provider.in' => 'Select PayMongo, PayPal, or Paynamics.',
            'api_username.required' => 'The Paynamics API username is required.',
            'api_password.required' => 'The Paynamics API password is required.',
            'paybiz_wallet_id.regex' => 'The Paybiz wallet ID may only contain letters, numbers, dashes and underscores.',
            'payment_channels.required' => 'Select at least one payment option enabled on your Paynamics account.',
            'payment_channels.min' => 'Select at least one payment option enabled on your Paynamics account.',
        ];
    }
}
