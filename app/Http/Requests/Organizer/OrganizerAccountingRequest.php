<?php

namespace App\Http\Requests\Organizer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizerAccountingRequest extends FormRequest
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

    public function eventUuid(): ?string
    {
        $value = $this->validated('event_uuid');

        return $value !== null && $value !== '' ? (string) $value : null;
    }
}
