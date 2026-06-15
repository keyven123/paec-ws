<?php

namespace App\Http\Requests\PromoCode;

use App\Constants\GeneralConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePromoCodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_uuid' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            'code' => ['required', 'string', 'max:255', Rule::unique('promo_codes', 'code')->whereNull('deleted_at')],
            'description' => ['nullable', 'string'],
            'activityable_type' => ['nullable', 'string'],
            'activityable_id' => ['nullable', 'uuid'],
            'discount_type' => ['required', 'string', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'discount_value' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    if ($this->input('discount_type') === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'] && $value > 100) {
                        $fail('The discount value cannot exceed 100 for percentage discounts.');
                    }
                },
            ],
            'is_unlimited' => ['nullable', 'boolean'],
            'max_use' => ['nullable', 'integer', 'min:1', 'required_if:is_unlimited,false'],
            'usable_from' => ['required', 'date'],
            'usable_to' => ['required', 'date', 'after:usable_from'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }
}

