<?php

namespace App\Http\Requests\OrganizationPlatformCom;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListOrganizationPlatformComRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('platform_default')) {
            $this->merge([
                'platform_default' => $this->boolean('platform_default'),
            ]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_uuid' => [
                'nullable',
                'uuid',
                'prohibited_if:platform_default,true',
                Rule::exists(Organization::class, 'uuid')->whereNull('deleted_at'),
            ],
            'platform_default' => ['sometimes', 'boolean'],
            'per_page' => ['integer', 'min:1', 'max:100'],
            'page' => ['integer', 'min:1'],
        ];
    }
}
