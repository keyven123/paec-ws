<?php

namespace App\Http\Requests\PasswordReset;

use Illuminate\Foundation\Http\FormRequest;

class PasswordResetUpdatePasswordRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'user_type' => ['required', 'in:admin,user'],
            'password_reset_uuid' => [
                'required',
                'exists:password_resets,uuid'
            ],
            'password' => ['required','string','min:8', 'regex:/^(?=.*[0-9])(?=.*[A-Z])(?=.*[!@#$%^&*_@.]).{8,}$/', 'confirmed'],
        ];
    }
}
