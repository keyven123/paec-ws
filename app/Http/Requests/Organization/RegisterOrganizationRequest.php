<?php

namespace App\Http\Requests\Organization;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterOrganizationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'unique:organizations,name', 'string', 'max:255'],
            'business_type' => ['required', 'string', Rule::in(Organization::BUSINESS_TYPES)],
            'representative_first_name' => ['required', 'string', 'max:60'],
            'representative_last_name' => ['required', 'string', 'max:60'],
            'address' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'unique:organizations,email',
                'unique:admin_users,email',
                'email',
                'max:50',
                // Local: letters, digits, . _ % + -  |  Domain labels: letters, digits, . -
                'regex:/^[\w.+%-]+@[\w.-]+(\.[\w.-]+)+$/',
            ],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
            'password' => ['required','string','min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
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
            'business_type.required' => 'Business type is required.',
            'business_type.in' => 'The business type is invalid.',
            'representative_first_name.required' => 'Organization representative first name is required.',
            'representative_last_name.required' => 'Organization representative last name is required.',
            'address.required' => 'Organization address is required.',
            'contact_number.required' => 'Organization contact number is required.',
            'email.required' => 'Organization email is required.',
            'email.unique' => 'Email address already exists.',
            'email.email' => 'Invalid email address.',
            'email.regex' => 'Invalid email format. Use letters and numbers, with . _ % + - before @ and . - in the domain (e.g. name@my-company.com).',
            'bank_name.required' => 'Organization bank name is required.',
            'bank_branch.required' => 'Organization bank branch is required.',
            'bank_address.required' => 'Organization bank address is required.',
            'bank_account_name.required' => 'Organization bank account name is required.',
            'bank_account_number.required' => 'Organization bank account number is required.',
            'status.in' => 'Invalid organization status.',
        ];
    }
}
