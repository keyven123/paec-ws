<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingRegisterRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'secret' => ['required', 'string', 'max:255'],
            'first_name'     => ['required','string','max:255'],
            'last_name'     => ['required','string','max:255'],
            'email'    => ['required','email','max:255','unique:admin_users,email'],
            'password' => ['required','string','min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
            'accepted_terms' => ['required','boolean', 'in:1'],
            'phone_number' => ['required', 'regex:/^(?:\+639|09|\d{1})\d{9}$/'],
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
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'Email is required.',
            'email.email' => 'Invalid email address.',
            'email.exists' => 'Email address not found.',
            'secret.required' => 'Secret is required.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'password.confirmed' => 'Password confirmation does not match.',
            'accepted_terms.required' => 'Accepted terms is required.',
            'accepted_terms.boolean' => 'Accepted terms must be a boolean.',
            'accepted_terms.in' => 'Please accept the terms and conditions.',
            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex' => 'Invalid phone number.',
        ];
    }
}
