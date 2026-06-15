<?php

namespace App\Http\Requests\Event;

use App\Constants\GeneralConstants;
use App\Http\Requests\Event\Concerns\MapsMetaPixelRequestFields;
use App\Models\Event;
use App\Models\EventSection;
use App\Services\PaecOrganizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEventRequest extends FormRequest
{
    use MapsMetaPixelRequestFields;
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $openPassEventSection = EventSection::where('name', EventSection::OPEN_PASS_SECTION)->first();
        return [
            'venue_uuid' => [
                'nullable',
                'uuid',
                'exists:venues,uuid',
                Rule::requiredIf(function () {
                    return $this->input('event_type') != Event::EVENT_TYPES['DAILY'];
                })
            ],
            'category_uuid' => ['required', 'uuid', 'exists:categories,uuid'],
            'organization_uuid' => [
                'nullable',
                'uuid',
                'exists:organizations,uuid',
            ],
            'event_section_uuid' => [
                'nullable',
                'uuid',
                'exists:event_sections,uuid',
                Rule::requiredIf(function () {
                    return $this->input('event_type') != Event::EVENT_TYPES['DAILY'];
                })
            ],
            'event_name' => ['required', 'string', 'max:255', Rule::unique(Event::class, 'event_name')
                ->whereNull('deleted_at')],
            'event_description' => ['required', 'string'],
            'contact_email' => ['required', 'email', 'max:255'],
            'logo_uuid' => ['nullable', 'uuid'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'portrait_image' => ['nullable', 'file', 'image', 'max:10240', 'mimes:png,jpg,jpeg,jfif'],
            'featured_image' => ['nullable', 'file', 'image', 'max:10240', 'mimes:png,jpg,jpeg,jfif'],
            'event_showcase' => ['nullable', 'array', 'max:6'],
            'event_showcase.*' => ['file', 'image', 'max:5120', 'mimes:png,jpg,jpeg,jfif'],
            'event_config' => ['required', 'string', Rule::in(array_values(Event::EVENT_CONFIGS))],
            'event_type' => ['required', Rule::in(array_values(Event::EVENT_TYPES))],
            'schedule_type' => ['nullable', Rule::in(array_values(Event::SCHEDULE_TYPES))],
            'schedules' => [
                'nullable',
                'array',
                Rule::requiredIf(function () {
                    return $this->input('event_type') != Event::EVENT_TYPES['DAILY'];
                })
            ],
            'schedules.*.date_from' => ['nullable', 'date'],
            'schedules.*.date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'schedules.*.status' => ['nullable', Rule::in(array_values(GeneralConstants::SCHEDULE_STATUSES))],
            'schedules.*.time' => ['nullable', 'array'],
            'schedules.*.time.*.time_start' => ['nullable', 'date_format:H:i:s'],
            'schedules.*.time.*.time_end' => ['nullable', 'date_format:H:i:s', 'after_or_equal:time_start'],
            'schedules.*.time.*.status' => ['nullable', Rule::in(array_values(GeneralConstants::SCHEDULE_STATUSES))],
            'ticket_available_from' => [
                'nullable',
                'date',
                Rule::requiredIf(function () use ($openPassEventSection) {
                    return !empty($this->input('tickets'))
                        && $this->input('event_type') !== 'daily'
                        && $openPassEventSection
                        && $this->input('event_section_uuid') !== $openPassEventSection->uuid;
                }),
            ],
            'ticket_available_to' => [
                'nullable',
                'date',
                'after_or_equal:ticket_available_from',
                Rule::requiredIf(function () use ($openPassEventSection) {
                    return !empty($this->input('tickets'))
                        && $this->input('event_type') !== 'daily'
                        && $openPassEventSection
                        && $this->input('event_section_uuid') !== $openPassEventSection->uuid;
                }),
            ],
            'tickets' => ['nullable', 'array'],
            'tickets.*.name' => ['required', 'string', 'max:255'],
            'tickets.*.description' => ['nullable', 'string'],
            'tickets.*.price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'tickets.*.max_ticket' => ['nullable', 'integer', 'min:0'],
            'tickets.*.is_unlimited' => ['nullable', 'string', 'in:true,false'],
            'tickets.*.is_virtual' => ['nullable', 'string', 'in:true,false'],
            'tickets.*.virtual_event_url' => ['nullable', 'url', 'max:500', Rule::requiredIf(function () {
                return $this->input('tickets.*.is_virtual') == 'true';
            })],
            'tickets.*.visit_policy' => ['nullable', 'string', Rule::in(['priority', 'flexible'])],
            'tickets.*.validity_days' => ['nullable', 'integer', 'min:1'],
            'excluded_dates' => ['nullable', 'array'],
            'excluded_dates.*' => ['date'],
            'is_featured' => ['nullable', 'boolean'],
            'featured_order' => ['nullable', 'integer', 'min:0'],
            'featured_from' => ['nullable', 'date'],
            'featured_until' => ['nullable', 'date', 'after_or_equal:featured_from'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'track_event_meta' => ['nullable', 'boolean'],
            'meta_pixel_id' => ['nullable', 'string', 'max:255', 'required_if:track_event_meta,true'],
            'meta_pixel_key' => ['nullable', 'string', 'max:500', 'required_if:track_event_meta,true'],
            'meta_test_event_code' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::EVENT_STATUSES))],
            'other_info' => ['nullable', 'array'],
            'other_info_deadline' => [
                'nullable',
                'date_format:Y-m-d H:i:s',
                'after_or_equal:now',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tickets = $this->input('tickets');
            if (!is_array($tickets)) {
                return;
            }
            foreach ($tickets as $index => $ticket) {
                if (($ticket['visit_policy'] ?? null) === 'flexible') {
                    $validityDays = $ticket['validity_days'] ?? null;
                    if ($validityDays === null || $validityDays === '' || (int) $validityDays < 1) {
                        $validator->errors()->add("tickets.{$index}.validity_days", 'Number of days is required when visit policy is Flexible Access.');
                    }
                }
            }
        });
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->prepareMetaPixelFieldsForValidation();

        if (!$this->filled('organization_uuid')) {
            $defaultOrganizationUuid = PaecOrganizationService::defaultOrganizationUuid();
            if ($defaultOrganizationUuid) {
                $this->merge(['organization_uuid' => $defaultOrganizationUuid]);
            }
        }

        // Decode other_info if it's sent as a JSON string
        if ($this->has('other_info') && is_string($this->input('other_info'))) {
            $decoded = json_decode($this->input('other_info'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge([
                    'other_info' => $decoded
                ]);
            }
        }
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'event_name.required' => 'Event name is required.',
            'event_description.required' => 'Event description is required.',
            'venue_uuid.required' => 'Venue is required.',
            'category_uuid.required' => 'Category is required.',
            'event_section_uuid.required' => 'Event section is required.',
            'contact_email.required' => 'Contact email is required.',
            'contact_email.email' => 'Please provide a valid email address.',
            'tickets.required_unless' => 'Tickets are required.',
            'ticket_available_from.required_unless' => 'Ticket available from is required.',
            'ticket_available_to.required_unless' => 'Ticket available to is required.',
            'ticket_available_from.date' => 'Ticket available from must be a valid date.',
            'ticket_available_to.date' => 'Ticket available to must be a valid date.',
            'ticket_available_from.after_or_equal' => 'Ticket available from must be after or equal to today.',
            'ticket_available_to.after_or_equal' => 'Ticket available to must be after or equal to ticket available from.',
            'tickets.*.name.required' => 'Ticket name is required.',
            'tickets.*.price.required' => 'Ticket price is required.',
            'tickets.*.price.numeric' => 'Ticket price must be a number.',
            'tickets.*.price.min' => 'Ticket price must be at least 0.',
            'tickets.*.price.max' => 'Ticket price must be less than 999999.99.',
            'tickets.*.max_ticket.min' => 'Max ticket must be at least 1.',
            'portrait_image.max' => 'Portrait image must be less than 10MB.',
            'portrait_image.mimes' => 'Portrait image must be a valid image (PNG, JPG, JPEG).',
            'featured_image.max' => 'Featured image must be less than 10MB.',
            'featured_image.mimes' => 'Featured image must be a valid image (PNG, JPG, JPEG).',
            'event_showcase.max' => 'Event showcase can have a maximum of 6 images.',
            'event_showcase.*.max' => 'Showcase image must be less than 5MB.',
            'event_showcase.*.mimes' => 'Showcase image must be a valid image (PNG, JPG, JPEG).',
            'organization_uuid.required_if' => 'Organization is required.',
        ];
    }
}
