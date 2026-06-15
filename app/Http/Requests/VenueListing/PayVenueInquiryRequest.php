<?php

namespace App\Http\Requests\VenueListing;

use App\Models\VenueInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayVenueInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payment_provider' => ['required', 'string', Rule::in(\App\Constants\GeneralConstants::PAYMENT_PROVIDERS)],
            'payment_phase' => ['nullable', 'string', Rule::in([
                VenueInquiry::PAYMENT_PHASE_DEPOSIT,
                VenueInquiry::PAYMENT_PHASE_BALANCE,
            ])],
            'return_url' => ['nullable', 'url'],
            'cancel_url' => ['nullable', 'url'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string'],
        ];
    }
}
