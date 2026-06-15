<?php

namespace App\Http\Requests\VenueSeat;

use App\Models\Venue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVenueSeatRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'venue_uuid' => [
                'required',
                'uuid',
                Rule::exists(Venue::class, 'uuid')->whereNull('deleted_at')
            ],
            'col' => ['required', 'string', 'max:10'],
            'row' => ['required', 'integer', 'min:1'],
            'seat_no' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(['bronze', 'silver', 'gold', 'vip', 'svip'])],
            'color' => ['required', Rule::in(['bronze', 'silver', 'gold', 'red', 'green'])],
            'status' => ['nullable', 'string'],
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
            'venue_uuid.exists' => 'The selected venue does not exist.',
            'category.in' => 'The category must be one of: bronze, silver, gold, vip, svip.',
            'color.in' => 'The color must be one of: bronze, silver, gold, red, green.',
        ];
    }
}
