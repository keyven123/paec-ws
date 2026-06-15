<?php

namespace App\Http\Requests\ActivityCompliance;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateActivityComplianceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $eventUuid = $this->input('event_uuid');

        return [
            'organization_uuid' => ['required', 'uuid', Rule::exists('organizations', 'uuid')],
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists('events', 'uuid')->where(function ($query) {
                    $query->where('organization_uuid', $this->input('organization_uuid'));
                }),
            ],
            'label' => [
                'required',
                'string',
                'max:255',
                Rule::unique('activity_compliances', 'label')
                    ->where('activityable_type', 'event')
                    ->where('activityable_id', $eventUuid),
            ],
            'percentage' => [
                Rule::requiredIf(fn () => $this->input('amount_type', ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'])
                    === ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']),
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'fixed_amount' => [
                Rule::requiredIf(fn () => $this->input('amount_type') === ActivityCompliance::AMOUNT_TYPE['FIXED']),
                'nullable',
                'numeric',
                'min:0',
                'max:99999999.99',
            ],
            'amount_type' => ['required', Rule::in(array_values(ActivityCompliance::AMOUNT_TYPE))],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::GENERAL_STATUSES))],
        ];
    }
}
