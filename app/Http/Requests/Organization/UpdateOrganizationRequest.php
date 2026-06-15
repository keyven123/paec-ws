<?php

namespace App\Http\Requests\Organization;

use App\Constants\GeneralConstants;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(Organization::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'representative_name' => ['sometimes', 'string', 'max:255'],
            'representative_first_name' => ['nullable', 'string', 'max:255'],
            'representative_last_name' => ['nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'string', 'max:255'],
            'contact_number' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::ORGANIZER_STATUSES))],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $merge = ['uuid' => $this->route('uuid')];

        foreach (
            [
                'bank_name',
                'bank_branch',
                'bank_address',
                'bank_account_name',
                'bank_account_number',
            ] as $field
        ) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }

        $this->merge($merge);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The name is required.',
            'representative_name.required' => 'The representative name is required.',
            'address.required' => 'The address is required.',
            'contact_number.required' => 'The contact number is required.',
            'email.required' => 'The email is required.',
            'bank_name.required' => 'The bank name is required.',
            'bank_branch.required' => 'The bank branch is required.',
            'bank_address.required' => 'The bank address is required.',
            'bank_account_name.required' => 'The bank account name is required.',
            'bank_account_number.required' => 'The bank account number is required.',
            'status.in' => 'The status is invalid.',
        ];
    }
}
