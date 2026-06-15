<?php

namespace App\Http\Requests\Organizer;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerUpdateAdminUserRequest extends FormRequest
{
    public function rules(): array
    {
        $adminUserUuid = $this->route('uuid');

        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(AdminUser::class, 'uuid')->whereNull('deleted_at'),
            ],
            'role_uuid' => [
                'sometimes',
                'uuid',
                'exists:roles,uuid',
                function ($attribute, $value, $fail) {
                    $role = Role::where('uuid', $value)->first();
                    if ($role && $role->code === GeneralConstants::ROLES['CUSTOMER']['name']) {
                        $fail('The selected role must be a valid staff role.');
                    }
                },
            ],
            'email' => [
                'sometimes',
                'email',
                Rule::unique('admin_users', 'email')->ignore($adminUserUuid, 'uuid'),
            ],
            'password' => ['sometimes', 'string', 'min:8'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid'),
        ]);
    }
}
