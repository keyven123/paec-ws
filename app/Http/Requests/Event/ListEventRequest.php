<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;
use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventSection;
use Illuminate\Validation\Rule;

class ListEventRequest extends FormRequest
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
            'sort' => ['nullable', Rule::in(['asc', 'desc'])],
            'sort_by' => ['nullable', Rule::in(['created_at'])],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'organization_uuid' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            'status' => ['nullable', Rule::in(GeneralConstants::EVENT_STATUSES)],
            'category_uuid' => ['nullable', 'uuid'],
            'address' => ['nullable', 'string'],
            'venue_uuid' => ['nullable', 'uuid'],
            'event_type' => ['nullable', Rule::in(array_values(Event::EVENT_TYPES))],
            'schedule_type' => ['nullable', Rule::in(array_values(Event::SCHEDULE_TYPES))],
            'event_section_type' => ['nullable', Rule::in(array_values(EventSection::EVENT_SECTION_TYPES))],
            'event_section_types' => ['nullable', 'array'],
            'event_section_types.*' => ['nullable', 'string', Rule::in(array_values(EventSection::EVENT_SECTION_TYPES))],
            'is_featured' => ['nullable', 'boolean'],
            'available_event' => ['nullable', 'boolean'],
            'fun_type' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'published' => ['nullable', 'boolean'],
            'is_request_for_featured' => ['nullable', 'boolean'],
            'affiliate_catalog' => ['nullable', Rule::in(['event', 'fun'])],
        ];
    }
}
