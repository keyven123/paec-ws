<?php

namespace App\Http\Requests\Customer;

use App\Models\TempTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutPaypalCardRequest extends FormRequest
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
                Rule::exists(TempTransaction::class, 'uuid'),
            ],
            'other_info' => ['nullable', 'array'],
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
        ];
    }
}
