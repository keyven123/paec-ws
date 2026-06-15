<?php

namespace App\Http\Requests\Organizer;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationProfileRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_type' => ['required', 'string', Rule::in(Organization::BUSINESS_TYPES)],
            'address' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:100'],
            // 'contact_number' => ['required', 'regex:/^(?:\+639|09|\d{1})\d{9}$/'],
            'email' => ['required', 'email', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string'],
            'commission_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
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
            'business_type.required' => 'Business type is required.',
            'business_type.in' => 'The business type is invalid.',
            'address.required' => 'Address is required.',
            'contact_number.required' => 'Contact number is required.',
            'contact_number.regex' => 'Invalid contact number.',
            'email.required' => 'Contact email is required.',
            'email.email' => 'Invalid email address.',
            'description.required' => 'Description is required.',
            'commission_percentage.numeric' => 'The commission percentage must be a number.',
            'commission_percentage.min' => 'The commission percentage must be at least 0.',
            'commission_percentage.max' => 'The commission percentage may not be greater than 100.',
        ];
    }
}
