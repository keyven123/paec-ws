<?php

namespace App\Http\Requests\Venue;

use App\Constants\GeneralConstants;
use App\Models\Venue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueRequest extends FormRequest
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
                Rule::exists(Venue::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'name' => ['sometimes', 'string', 'max:255', Rule::unique(Venue::class, 'name')->ignore($this->route('uuid'))],
            'type' => ['sometimes', 'string', 'max:255', Rule::in(array_values(Venue::TYPES))],
            'image_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:media,uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid')
        ]);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This venue name already exists.',
            'type.in' => 'Invalid venue type.',
        ];
    }
}
