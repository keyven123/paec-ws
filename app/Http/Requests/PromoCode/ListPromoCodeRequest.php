<?php

namespace App\Http\Requests\PromoCode;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\GeneralConstants;
use Illuminate\Validation\Rule;

class ListPromoCodeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'status' => ['nullable', Rule::in(GeneralConstants::GENERAL_STATUSES)],
            'organization_uuid' => ['nullable', 'uuid'],
            'discount_type' => ['nullable', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'is_unlimited' => ['nullable', 'boolean'],
            'activityable_type' => ['nullable', 'string'],
            'activityable_id' => ['nullable', 'uuid'],
        ];
    }
}

