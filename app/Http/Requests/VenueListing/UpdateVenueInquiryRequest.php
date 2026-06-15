<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVenueInquiryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visit_scheduled_date' => ['nullable', 'date'],
            'visit_scheduled_time' => ['nullable', 'date_format:H:i'],
            'cancel' => ['nullable', 'boolean'],
        ];
    }
}
