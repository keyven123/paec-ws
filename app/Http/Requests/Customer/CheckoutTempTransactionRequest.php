<?php

namespace App\Http\Requests\Customer;

use App\Constants\GeneralConstants;
use App\Models\TempTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutTempTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'temp_transaction_uuid' => [
                'required',
                'uuid',
                Rule::exists(TempTransaction::class, 'uuid')
            ],
            'payment_provider' => ['required', 'string', Rule::in(GeneralConstants::PAYMENT_PROVIDERS)],
            'return_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'payment_methods' => ['nullable', 'array'], // For PayMongo
            'payment_methods.*' => ['string', Rule::in(['shopee_pay', 'qrph', 'billease', 'card', 'dob', 'dob_ubp', 'brankas_bdo', 'brankas_landbank', 'brankas_metrobank', 'gcash', 'grab_pay', 'paymaya'])],
            'other_info' => ['nullable', 'array']
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'temp_transaction_uuid.required' => 'Temp transaction uuid is required.',
            'temp_transaction_uuid.uuid' => 'Temp transaction uuid must be a valid uuid.',
            'temp_transaction_uuid.exists' => 'Temp transaction uuid does not exist.',
            'payment_provider.required' => 'Payment provider is required.',
            'payment_provider.string' => 'Payment provider must be a string.',
            'payment_provider.in' => 'Payment provider must be a valid payment provider.',
            'return_url.url' => 'Return URL must be a valid URL.',
            'cancel_url.url' => 'Cancel URL must be a valid URL.',
            'payment_methods.array' => 'Payment methods must be an array.',
            'payment_methods.*.string' => 'Each payment method must be a string.',
            'payment_methods.*.in' => 'Invalid payment method provided.',
        ];
    }
}
