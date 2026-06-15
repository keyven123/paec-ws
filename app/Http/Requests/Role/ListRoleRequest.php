<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class ListRoleRequest extends FormRequest
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
            'is_admin' => ['nullable', 'boolean'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
        ];
    }
}
