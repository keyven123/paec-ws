<?php

namespace App\Http\Requests\Organizer;

use App\Constants\GeneralConstants;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerUpdateCustomerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(User::class, 'uuid')->whereNull('deleted_at'),
            ],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $this->route('uuid') . ',uuid'],
            'password' => ['sometimes', 'min:8', 'confirmed'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
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
