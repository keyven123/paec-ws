<?php

namespace App\Http\Requests\VenueSeat;

use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueSeatRequest extends FormRequest
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
                Rule::exists(VenueSeat::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'venue_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(Venue::class, 'uuid')->whereNull('deleted_at')
            ],
            'col' => ['sometimes', 'string', 'max:10'],
            'row' => ['sometimes', 'integer', 'min:1'],
            'seat_no' => ['sometimes', 'integer', 'min:1'],
            'category' => ['sometimes', Rule::in(['bronze', 'silver', 'gold', 'vip', 'svip'])],
            'color' => ['sometimes', Rule::in(['bronze', 'silver', 'gold', 'red', 'green'])],
            'status' => ['nullable', 'string'],
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
            'venue_uuid.exists' => 'The selected venue does not exist.',
            'category.in' => 'The category must be one of: bronze, silver, gold, vip, svip.',
            'color.in' => 'The color must be one of: bronze, silver, gold, red, green.',
        ];
    }
}
