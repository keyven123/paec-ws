<?php

namespace App\Http\Requests\Venue;

use App\Constants\GeneralConstants;
use App\Models\Venue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVenueRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:venues,name'],
            'type' => ['required', 'string', 'max:255', Rule::in(array_values(Venue::TYPES))],
            'image_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Venue name is required.',
            'name.unique' => 'This venue name already exists.',
            'type.in' => 'Invalid venue type.',
            'type.required' => 'Venue type is required.',
        ];
    }
}
