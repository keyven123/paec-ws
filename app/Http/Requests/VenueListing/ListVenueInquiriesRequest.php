<?php

namespace App\Http\Requests\VenueListing;

use App\Models\VenueInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVenueInquiriesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'status' => [
                'nullable',
                'string',
                Rule::in([
                    'all',
                    ...array_values(VenueInquiry::STATUSES),
                    'visit-schedule',
                ]),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
