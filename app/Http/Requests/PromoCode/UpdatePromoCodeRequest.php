<?php

namespace App\Http\Requests\PromoCode;

use App\Constants\GeneralConstants;
use App\Models\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromoCodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(PromoCode::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'organization_uuid' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('promo_codes', 'code')->ignore($this->route('uuid'), 'uuid')->whereNull('deleted_at')
            ],
            'description' => ['nullable', 'string'],
            'activityable_type' => ['nullable', 'string'],
            'activityable_id' => ['nullable', 'uuid'],
            'discount_type' => ['sometimes', 'string', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'discount_value' => [
                'sometimes',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) {
                    $discountType = $this->input('discount_type');
                    // If discount_type is not provided, get it from the existing model
                    if (!$discountType && $this->route('uuid')) {
                        $promoCode = \App\Models\PromoCode::where('uuid', $this->route('uuid'))->first();
                        if ($promoCode) {
                            $discountType = $promoCode->discount_type;
                        }
                    }
                    if ($discountType === GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'] && $value > 100) {
                        $fail('The discount value cannot exceed 100 for percentage discounts.');
                    }
                },
            ],
            'is_unlimited' => ['nullable', 'boolean'],
            'max_use' => ['nullable', 'integer', 'min:1', 'required_if:is_unlimited,false'],
            'usable_from' => ['sometimes', 'date'],
            'usable_to' => ['sometimes', 'date', 'after:usable_from'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid')
        ]);
    }
}

