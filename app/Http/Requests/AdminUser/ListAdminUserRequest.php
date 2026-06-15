<?php

namespace App\Http\Requests\AdminUser;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\GeneralConstants;
use Illuminate\Validation\Rule;

class ListAdminUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['string'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'status' => ['nullable', Rule::in(GeneralConstants::GENERAL_STATUSES)],
            'is_admin' => ['nullable', 'boolean'],
            'role_uuid' => ['nullable', 'uuid', 'exists:roles,uuid']
        ];
    }
}
