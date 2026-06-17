<?php

namespace App\Http\Requests\Organizer;

use App\Services\Platform\AdminPlatformPnLService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerPnLRequest extends FormRequest
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
        $organizationUuid = auth('admin')->user()?->organization_uuid;

        return [
            'period' => [
                'nullable',
                'string',
                Rule::in(AdminPlatformPnLService::allowedPeriods()),
            ],
            'as_of' => ['nullable', 'date'],
            'custom_start' => [
                Rule::requiredIf(fn () => $this->input('period') === AdminPlatformPnLService::PERIOD_CUSTOM),
                'nullable',
                'date',
            ],
            'custom_end' => [
                Rule::requiredIf(fn () => $this->input('period') === AdminPlatformPnLService::PERIOD_CUSTOM),
                'nullable',
                'date',
                'after_or_equal:custom_start',
            ],
            'event_uuid' => [
                'nullable',
                'uuid',
                Rule::exists('events', 'uuid')->where(function ($query) use ($organizationUuid) {
                    if ($organizationUuid) {
                        $query->where('organization_uuid', $organizationUuid);
                    }
                }),
            ],
        ];
    }
}
