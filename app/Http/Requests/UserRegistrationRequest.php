<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRegistrationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name'     => ['required','string','max:255'],
            'last_name'     => ['required','string','max:255'],
            'birth_date'     => ['required','date'],
            'email'    => ['required','email','max:255','unique:users,email'],
            'phone_number' => ['required','string','regex:/^\+[1-9]\d{1,14}$/', Rule::unique('users', 'phone_number')],
            'password' => ['required','string','min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
            'marketing_consent' => ['required','boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'The password confirmation does not match.',
            'marketing_consent.required' => 'Marketing consent is required.',
        ];
    }
}
