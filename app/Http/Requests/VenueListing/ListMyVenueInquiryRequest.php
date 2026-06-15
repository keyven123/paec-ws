<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class ListMyVenueInquiryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
