<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMerchantCommissionSettingRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_commission_percentage.required' => 'The default commission percentage is required.',
            'default_commission_percentage.numeric' => 'The default commission percentage must be a number.',
            'default_commission_percentage.min' => 'The default commission percentage must be at least 0.',
            'default_commission_percentage.max' => 'The default commission percentage may not be greater than 100.',
        ];
    }
}
