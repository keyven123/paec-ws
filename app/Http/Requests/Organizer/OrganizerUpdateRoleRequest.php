<?php

namespace App\Http\Requests\Organizer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerUpdateRoleRequest extends FormRequest
{
    public function rules(): array
    {
        $organizationUuid = auth('admin')->user()?->organization_uuid;
        $roleUuid = $this->route('uuid');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'code')
                    ->where(function ($query) use ($organizationUuid) {
                        if ($organizationUuid) {
                            $query->where('organization_uuid', $organizationUuid);
                        }
                    })
                    ->ignore($roleUuid, 'uuid'),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,uuid'],
            'permission_grants' => ['array'],
            'permission_grants.*.code' => ['required_with:permission_grants', 'string', 'exists:permissions,code'],
            'permission_grants.*.available_access' => ['required_with:permission_grants', 'string', 'max:32'],
        ];
    }
}
