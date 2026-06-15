<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid', 'exists:roles,uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:roles,code,' . $this->uuid . ',uuid'],
            'is_admin' => ['sometimes', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,uuid'],
            'permission_grants' => ['array'],
            'permission_grants.*.code' => ['required_with:permission_grants', 'string', 'exists:permissions,code'],
            'permission_grants.*.available_access' => ['required_with:permission_grants', 'string', 'max:32'],
        ];
    }
}
