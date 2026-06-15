<?php

namespace App\Http\Requests\Organizer;

use App\Models\OrganizationBank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationBankRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_type' => ['required', 'string', Rule::in(OrganizationBank::ACCOUNT_TYPES)],
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_branch' => ['required', 'string', 'max:255'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['required', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(OrganizationBank::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_name.required' => 'Bank name is required.',
            'account_type.required' => 'Account type is required.',
            'account_type.in' => 'The account type is invalid.',
            'bank_branch.required' => 'Bank branch is required.',
            'bank_account_name.required' => 'Bank account name is required.',
            'bank_account_number.required' => 'Bank account number is required.',
            'status.in' => 'The bank account status is invalid.',
        ];
    }
}
