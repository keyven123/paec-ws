<?php

namespace App\Http\Requests\AdminUser;

use App\Constants\GeneralConstants;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAdminUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role_uuid' => [
                'required',
                'uuid',
                'exists:roles,uuid',
                function ($attribute, $value, $fail) {
                    $role = \App\Models\Role::where('uuid', $value)->first();
                    if ($role && $role->code === \App\Constants\GeneralConstants::ROLES['CUSTOMER']['name']) {
                        $fail('The selected role must be a valid admin role (not customer).');
                    }
                }
            ],
            'organization_uuid' => ['sometimes', 'uuid', 'exists:organizations,uuid'],
            'email' => ['required', 'email', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
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
            'role_uuid.exists' => 'The selected role must be a valid admin role (not customer).',
            'email.unique' => 'An admin user with this email already exists.',
        ];
    }
}
