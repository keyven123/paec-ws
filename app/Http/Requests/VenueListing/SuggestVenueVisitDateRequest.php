<?php

namespace App\Http\Requests\VenueListing;

use Illuminate\Foundation\Http\FormRequest;

class SuggestVenueVisitDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'suggested_date' => ['required', 'date', 'after_or_equal:today'],
            'suggested_time' => ['required', 'date_format:H:i'],
        ];
    }
}
