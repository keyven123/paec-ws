<?php

namespace App\Http\Requests\Customer;

class GuestCheckoutTempTransactionRequest extends CheckoutFreeTempTransactionRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email' => ['required', 'email', 'max:255'],
        ]);
    }
}
