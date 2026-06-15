<?php

namespace App\Http\Requests;

use App\Constants\GeneralConstants;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required','email'],
            'password' => ['required','string'],
            'user_type' => ['nullable', 'string', Rule::notIn([GeneralConstants::ROLES['CUSTOMER']['name']])],
            'is_admin' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_type.not_in' => 'Invalid Credentials.',
        ];
    }
}
