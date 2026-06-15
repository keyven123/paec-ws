<?php

namespace App\Http\Requests\AdminUser;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $adminUserUuid = $this->route('uuid');

        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(AdminUser::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'role_uuid' => [
                'sometimes',
                'uuid',
                'exists:roles,uuid',
                function ($attribute, $value, $fail) {
                    $role = \App\Models\Role::where('uuid', $value)->first();
                    if ($role && $role->code === \App\Constants\GeneralConstants::ROLES['CUSTOMER']['name']) {
                        $fail('The selected role must be a valid admin role (not customer).');
                    }
                }
            ],
            'email' => [
                'sometimes',
                'email',
                Rule::unique('admin_users', 'email')->ignore($adminUserUuid, 'uuid')
            ],
            'organization_uuid' => ['sometimes', 'uuid', 'exists:organizations,uuid'],
            'password' => ['sometimes', 'string', 'min:8'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid')
        ]);
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
