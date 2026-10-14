<?php

namespace App\Http\Requests\PaymentGateway;

use App\Constants\GeneralConstants;
use App\Services\Payments\PaynamicsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentGatewayRequest extends FormRequest
{
    /**
     * Blank key fields mean "keep the stored value", so admins never have to re-enter secrets.
     */
    protected function prepareForValidation(): void
    {
        $blankKeys = array_filter(
            ['public_key', 'secret_key', 'api_username', 'api_password'],
            fn (string $field) => $this->exists($field) && trim((string) $this->input($field)) === '',
        );

        if ($blankKeys !== []) {
            $this->replace($this->except($blankKeys));
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'provider' => ['sometimes', Rule::in(array_values(GeneralConstants::PAYMENT_GATEWAY_PROVIDERS))],
            'gateway_id' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('payment_gateways', 'gateway_id')
                    ->whereNull('deleted_at')
                    ->ignore($this->route('uuid'), 'uuid'),
            ],
            'public_key' => ['sometimes', 'string', 'max:2000'],
            'secret_key' => ['sometimes', 'string', 'max:2000'],
            'api_username' => ['sometimes', 'string', 'max:255'],
            'api_password' => ['sometimes', 'string', 'max:255'],
            'paybiz_wallet_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:' . PaynamicsService::PAYBIZ_WALLET_ID_PATTERN],
            'payment_channels' => ['sometimes', 'array', 'min:1'],
            'payment_channels.*' => ['distinct', Rule::in(array_keys(PaynamicsService::CHANNELS))],
            'status' => ['sometimes', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
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
            'paybiz_wallet_id.regex' => 'The Paybiz wallet ID may only contain letters, numbers, dashes and underscores.',
            'payment_channels.min' => 'Select at least one payment option enabled on your Paynamics account.',
        ];
    }
}
