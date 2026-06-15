<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class SendVenueFinalBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'balance_amount' => ['required', 'numeric', 'min:0'],
            'balance_due_date' => ['required', 'date'],
            'additional_charges' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
