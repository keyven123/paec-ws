<?php

namespace App\Http\Requests\PasswordReset;

use Illuminate\Foundation\Http\FormRequest;

class ExpiredPasswordResetUpdatePasswordRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'password_set_uuid' => [
                'required',
                'exists:password_setups,uuid'
            ],
            'password' => ['required','string','min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
        ];
    }
}


