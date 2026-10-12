<?php

namespace App\Http\Requests\Customer;

class GuestPayTempTransactionRequest extends CheckoutTempTransactionRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'payment_gateway_uuid' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:255'],
        ]);
    }
}
