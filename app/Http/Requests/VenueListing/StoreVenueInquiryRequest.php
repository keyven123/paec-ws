<?php

namespace App\Http\Requests\VenueListing;

use App\Models\VenueInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVenueInquiryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'event_type' => ['nullable', 'string', 'max:100'],
            'event_date' => ['nullable', 'date'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'site_visit' => ['nullable', Rule::in(VenueInquiry::SITE_VISITS)],
            'message' => ['nullable', 'string', 'max:2000'],
            'initial_chat_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
