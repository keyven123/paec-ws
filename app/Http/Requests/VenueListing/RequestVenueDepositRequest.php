<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class RequestVenueDepositRequest extends FormRequest
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
            'deposit_amount' => ['required', 'numeric', 'min:0.01'],
            'deposit_due_date' => ['required', 'date'],
        ];
    }
}
