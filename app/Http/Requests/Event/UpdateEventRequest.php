<?php

namespace App\Http\Requests\Event;

use App\Constants\GeneralConstants;
use App\Http\Requests\Event\Concerns\MapsMetaPixelRequestFields;
use App\Models\Event;
use App\Models\EventSection;
use App\Services\PaecOrganizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    use MapsMetaPixelRequestFields;
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
                Rule::exists(Event::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'venue_uuid' => [
                'nullable',
                'uuid',
                'exists:venues,uuid',
                Rule::requiredIf(function () {
                    return $this->input('event_type') != Event::EVENT_TYPES['DAILY'];
                })],
            'organization_uuid' => ['nullable', 'uuid', 'exists:organizations,uuid'],
            'category_uuid' => ['required', 'uuid', 'exists:categories,uuid'],
            'event_section_uuid' => [
                'nullable',
                'uuid',
                'exists:event_sections,uuid',
                Rule::requiredIf(function () {
                    return $this->input('event_type') != Event::EVENT_TYPES['DAILY'];
                })
            ],
            'event_name' => [
                'required',
                'string',
                'max:200',
                Rule::unique(Event::class, 'event_name')
                    ->ignore($this->route('uuid'), 'uuid')
                    ->whereNull('deleted_at')
            ],
            'slug' => ['required', 'string', 'max:255', Rule::unique(Event::class, 'slug')
                ->ignore($this->route('uuid'), 'uuid')
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
            'event_config' => ['nullable', '', 'string', Rule::in(array_values(Event::EVENT_CONFIGS))],
            'event_type' => ['required', Rule::in(array_values(Event::EVENT_TYPES))],
            'status' => ['nullable', Rule::in(array_values(GeneralConstants::EVENT_STATUSES))],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'track_event_meta' => ['nullable', 'boolean'],
            'meta_pixel_id' => ['nullable', 'string', 'max:255', 'required_if:track_event_meta,true'],
            'meta_pixel_key' => ['nullable', 'string', 'max:500', 'required_if:track_event_meta,true'],
            'meta_test_event_code' => ['nullable', 'string', 'max:255'],
            'other_info' => ['nullable', 'array'],
            'other_info_deadline' => [
                'nullable',
                'date_format:Y-m-d H:i:s',
                'after_or_equal:now',
            ],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid'),
        ]);

        if (!$this->filled('organization_uuid')) {
            $eventUuid = $this->route('uuid');
            $existingOrganizationUuid = $eventUuid
                ? Event::query()->where('uuid', $eventUuid)->value('organization_uuid')
                : null;

            $organizationUuid = $existingOrganizationUuid
                ?: auth('admin')->user()?->organization_uuid
                ?: PaecOrganizationService::defaultOrganizationUuid();

            if ($organizationUuid) {
                $this->merge(['organization_uuid' => $organizationUuid]);
            }
        }

        $this->prepareMetaPixelFieldsForValidation();

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
            'contact_email.email' => 'Please provide a valid email address.',
            'event_showcase.max' => 'Event showcase can have a maximum of 6 images.',
            'event_showcase.*.max' => 'Showcase image must be less than 5MB.',
            'event_showcase.*.mimes' => 'Showcase image must be a valid image (PNG, JPG, JPEG).',
            'portrait_image.max' => 'Portrait image must be less than 10MB.',
            'portrait_image.mimes' => 'Portrait image must be a valid image (PNG, JPG, JPEG).',
            'featured_image.max' => 'Featured image must be less than 10MB.',
            'featured_image.mimes' => 'Featured image must be a valid image (PNG, JPG, JPEG).',
        ];
    }
}
