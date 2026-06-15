<?php

namespace App\Http\Requests\Organizer;

use App\Models\OrganizationBank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncOrganizationBanksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'banks' => ['required', 'array'],
            'banks.*.uuid' => ['nullable', 'uuid'],
            'banks.*.account_type' => ['required', 'string', Rule::in(OrganizationBank::ACCOUNT_TYPES)],
            'banks.*.bank_name' => ['required', 'string', 'max:255'],
            'banks.*.bank_branch' => ['required', 'string', 'max:255'],
            'banks.*.bank_address' => ['nullable', 'string', 'max:255'],
            'banks.*.bank_account_name' => ['required', 'string', 'max:255'],
            'banks.*.bank_account_number' => ['required', 'string', 'max:255'],
            'banks.*.is_default' => ['sometimes', 'boolean'],
            'banks.*.status' => ['sometimes', 'string', Rule::in(OrganizationBank::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'banks.required' => 'At least one bank account is required.',
            'banks.*.bank_name.required' => 'Bank name is required.',
            'banks.*.account_type.required' => 'Account type is required.',
            'banks.*.account_type.in' => 'The account type is invalid.',
            'banks.*.bank_branch.required' => 'Bank branch is required.',
            'banks.*.bank_account_name.required' => 'Bank account name is required.',
            'banks.*.bank_account_number.required' => 'Bank account number is required.',
            'banks.*.status.in' => 'The bank account status is invalid.',
        ];
    }
}
