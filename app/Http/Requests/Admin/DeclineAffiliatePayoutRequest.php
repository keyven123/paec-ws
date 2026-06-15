<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeclineAffiliatePayoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
