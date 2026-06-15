<?php

namespace App\Http\Requests\Transaction;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_uuid' => [
                'required',
                'uuid',
                Rule::exists(User::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at')
            ],
            'paypal_order_id' => ['nullable', 'string', 'max:255'],
            'order_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('transactions', 'order_number')->whereNull('deleted_at')
            ],
            'total_amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'sub_total' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'status' => ['required', 'string', 'max:50'],
            'payment_status' => ['required', 'string', 'max:50'],
            'order_status' => ['required', 'string', 'max:50'],
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
            'user_uuid.exists' => 'The selected user does not exist.',
            'event_uuid.exists' => 'The selected event does not exist.',
            'order_number.unique' => 'This order number is already in use.',
        ];
    }
}
