<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationCommissionPercentageRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commission_percentage.required' => 'The commission percentage is required.',
            'commission_percentage.numeric' => 'The commission percentage must be a number.',
            'commission_percentage.min' => 'The commission percentage must be at least 0.',
            'commission_percentage.max' => 'The commission percentage may not be greater than 100.',
        ];
    }
}
