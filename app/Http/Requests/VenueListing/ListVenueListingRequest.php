<?php

namespace App\Http\Requests\VenueListing;

use App\Models\VenueListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVenueListingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_values(VenueListing::STATUSES))],
            'category' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'guests' => ['nullable', 'integer', 'min:1'],
            'organization_uuid' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
