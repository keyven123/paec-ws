<?php

namespace App\Http\Requests\ActivityCompliance;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateActivityComplianceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $compliance = ActivityCompliance::query()->find($this->route('uuid'));

        return [
            'label' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('activity_compliances', 'label')
                    ->where('activityable_type', $compliance?->activityable_type ?? 'event')
                    ->where('activityable_id', $compliance?->activityable_id)
                    ->ignore($this->route('uuid'), 'uuid'),
            ],
            'status' => ['sometimes', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
            'percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'amount_type' => ['sometimes', Rule::in(array_values(ActivityCompliance::AMOUNT_TYPE))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid'),
        ]);
    }
}
