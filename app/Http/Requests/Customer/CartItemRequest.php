<?php

namespace App\Http\Requests\Customer;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CartItemRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')
                    ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at'),
            ],
            'event_location_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(EventLocation::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->where('is_active', true),
            ],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'promo_code_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(PromoCode::class, 'uuid')
                    ->whereNull('deleted_at')
                    ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE']),
            ],
            'tickets' => ['required', 'array', 'min:1', 'max:20'],
            'tickets.*.event_ticket_uuid' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists(EventTicket::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->whereNull('deleted_at'),
            ],
            'tickets.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'details' => ['nullable', 'array'],
            'details.attractionName' => ['nullable', 'string', 'max:255'],
            'details.location' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $event = Event::query()->findOrFail($this->input('event_uuid'));
            if ($event->event_config === Event::EVENT_CONFIGS['SEAT_SELECTION']) {
                $validator->errors()->add('event_uuid', 'Activities with seat selection cannot be added to the cart.');

                return;
            }

            if (! $event->isVisitDateBookable($this->input('visit_date'))) {
                $validator->errors()->add('visit_date', "Today's visit date is no longer available. Please choose another date.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event_uuid.exists' => 'This activity is no longer available.',
            'visit_date.required' => 'Please select a date of visit.',
            'visit_date.after_or_equal' => 'The date of visit cannot be in the past.',
            'tickets.required' => 'Please add at least one ticket.',
            'tickets.*.event_ticket_uuid.exists' => 'This ticket is no longer available for the activity.',
            'tickets.*.quantity.min' => 'Each ticket needs a quantity of at least 1.',
        ];
    }
}
