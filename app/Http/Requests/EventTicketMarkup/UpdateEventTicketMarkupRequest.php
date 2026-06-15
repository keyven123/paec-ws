<?php

namespace App\Http\Requests\EventTicketMarkup;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventTicketMarkupRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_uuid' => ['required', 'uuid'],
            'markup_type' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'markup_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:' . ($this->input('markup_type') === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'] ? '100' : '999999.99'),
                Rule::requiredIf(fn () => filled($this->input('markup_type'))),
            ],
        ];
    }
}
