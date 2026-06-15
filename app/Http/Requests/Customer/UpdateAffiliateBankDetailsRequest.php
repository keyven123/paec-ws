<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAffiliateBankDetailsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bank' => ['required', 'string', 'max:191'],
            'branch' => ['required', 'string', 'max:191'],
            'account_name' => ['required', 'string', 'max:191'],
            'account_number' => ['required', 'string', 'max:100'],
            'tin' => ['required', 'string', 'max:32'],
        ];
    }
}
