<?php

namespace App\Http\Requests\Organizer;

use App\Constants\GeneralConstants;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerCreateAdminUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role_uuid' => [
                'required',
                'uuid',
                'exists:roles,uuid',
                function ($attribute, $value, $fail) {
                    $role = Role::where('uuid', $value)->first();
                    if ($role && $role->code === GeneralConstants::ROLES['CUSTOMER']['name']) {
                        $fail('The selected role must be a valid staff role.');
                    }
                },
            ],
            'email' => ['required', 'email', 'unique:admin_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }
}
