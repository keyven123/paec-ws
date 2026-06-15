<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class SuspendAffiliatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
