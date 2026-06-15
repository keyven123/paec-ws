<?php

namespace App\Http\Requests\EventTicket;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEventTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $event = Event::find($this->input('event_uuid'));
        if ($event && $event->event_type == Event::EVENT_TYPES['DAILY']) {
            $scheduleUuidRule = 'nullable';
            $scheduleTimeUuidRule = 'nullable';
        } else {
            $scheduleUuidRule = 'required';
            $scheduleTimeUuidRule = 'required';
        }
        return [
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at')
            ],
            'schedule_uuid' => [
                $scheduleUuidRule,
                'uuid',
                Rule::exists(Schedule::class, 'uuid')->whereNull('deleted_at')
            ],
            'schedule_time_uuid' => [
                $scheduleTimeUuidRule,
                'uuid',
                Rule::exists(ScheduleTime::class, 'uuid')->whereNull('deleted_at')
            ],
            'code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('event_tickets', 'code')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'is_bundle' => ['nullable', 'boolean'],
            'bundle_quantity' => ['nullable', 'integer', 'min:1'],
            'discount_type' => ['nullable', 'string', Rule::in(array_values(GeneralConstants::DISCOUNT_TYPES))],
            'discount_value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:' . ($this->input('discount_type') == GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'] ? '100' : $this->input('price')),
            ],
            'bundle_tickets' => ['nullable', 'array'],
            'bundle_tickets.*' => ['uuid', 'exists:event_tickets,uuid'],
            'available_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'available_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:available_from'],
            'display_order' => ['nullable', 'integer', 'min:1'],
            'max_ticket' => ['nullable', 'integer', 'min:0'],
            'ticket_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'is_unlimited' => ['required', 'boolean'],
            'is_virtual' => ['nullable', 'boolean'],
            'virtual_event_url' => ['nullable', 'url', 'max:500', Rule::requiredIf(function () {
                return $this->input('is_virtual') == true;
            })],
            'visit_policy' => ['nullable', 'string', Rule::in(['priority', 'flexible'])],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(GeneralConstants::GENERAL_STATUSES)],
            'bg_color' => ['nullable', 'string', 'max:255'],
            'with_coupon' => ['nullable', 'boolean'],
            'coupons' => ['nullable', 'array', Rule::requiredIf(function () {
                return $this->input('with_coupon') == true;
            })],
            'coupons.*' => ['required', 'array'],
            'coupons.*.name' => ['required', 'string', 'max:255'],
            'coupons.*.once_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (($this->input('visit_policy') ?? null) === 'flexible') {
                $validityDays = $this->input('validity_days');
                if ($validityDays === null || $validityDays === '' || (int) $validityDays < 1) {
                    $validator->errors()->add('validity_days', 'Number of days is required when visit policy is Flexible Access.');
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'available_to.after_or_equal' => 'The available to date must be after or equal to the available from date.',
            'code.unique' => 'This code is already used for another ticket in this event.',
            'event_uuid.exists' => 'The selected event does not exist.',
            'schedule_time_uuid.exists' => 'The selected schedule time does not exist.',
        ];
    }
}
