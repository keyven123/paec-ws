<?php

namespace App\Http\Requests\Organization;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrganizationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'representative_name' => ['required', 'unique:organizations,representative_name', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:255'],
            'email' => ['required', 'unique:organizations,email', 'email', 'max:255'],
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_branch' => ['required', 'string', 'max:255'],
            'bank_address' => ['required', 'string', 'max:255'],
            'bank_account_name' => ['required', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::ORGANIZER_STATUSES))],
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
            'name.required' => 'Organization name is required.',
            'representative_name.required' => 'Organization representative name is required.',
            'address.required' => 'Organization address is required.',
            'contact_number.required' => 'Organization contact number is required.',
            'email.required' => 'Organization email is required.',
            'bank_name.required' => 'Organization bank name is required.',
            'bank_branch.required' => 'Organization bank branch is required.',
            'bank_address.required' => 'Organization bank address is required.',
            'bank_account_name.required' => 'Organization bank account name is required.',
            'bank_account_number.required' => 'Organization bank account number is required.',
            'status.in' => 'Invalid organization status.',
        ];
    }
}
