<?php

namespace App\Http\Requests\Organizer;

use App\Models\OrganizationBank;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizerMerchantPayoutRequest extends FormRequest
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
        $organizationUuid = $this->user('admin')?->organization_uuid;

        return [
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists('events', 'uuid')->where(function ($query) use ($organizationUuid) {
                    if ($organizationUuid) {
                        $query->where('organization_uuid', $organizationUuid);
                    }
                }),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'organization_bank_uuid' => [
                'required',
                'uuid',
                Rule::exists('organization_banks', 'uuid')->where(function ($query) use ($organizationUuid) {
                    if ($organizationUuid) {
                        $query->where('organization_uuid', $organizationUuid)
                            ->where('status', OrganizationBank::STATUS_ACTIVE);
                    }
                }),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_uuid.required' => 'Select an event for this payout request.',
            'organization_bank_uuid.required' => 'Select a preferred bank account.',
            'organization_bank_uuid.exists' => 'The selected bank account is invalid or inactive.',
            'amount.required' => 'Enter a payout amount.',
            'amount.min' => 'Enter a payout amount greater than zero.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $orgUuid = $this->user('admin')?->organization_uuid;
            if (! $orgUuid) {
                return;
            }

            $eventUuid = $this->input('event_uuid');
            if (! is_string($eventUuid) || $eventUuid === '') {
                return;
            }

            $amount = round((float) $this->input('amount'), 2);
            $available = app(OrganizerAccountingBalanceService::class)->availableForPayout($orgUuid, $eventUuid);

            if ($amount > $available + 0.009) {
                $validator->errors()->add(
                    'amount',
                    'Amount exceeds available balance for this event (PHP ' . number_format($available, 2) . ').'
                );
            }
        });
    }
}
