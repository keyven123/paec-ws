<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class AssignPermissionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['required_without:permission_grants', 'array'],
            'permissions.*' => ['string', 'exists:permissions,uuid'],
            'permission_grants' => ['required_without:permissions', 'array'],
            'permission_grants.*.code' => ['required_with:permission_grants', 'string', 'exists:permissions,code'],
            'permission_grants.*.available_access' => ['required_with:permission_grants', 'string', 'max:32'],
        ];
    }
}
