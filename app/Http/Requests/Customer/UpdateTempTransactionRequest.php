<?php

namespace App\Http\Requests\Customer;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\PromoCode;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TempTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTempTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'temp_transaction_uuid' => [
            'required',
                'uuid',
                Rule::exists(TempTransaction::class, 'uuid')
            ],
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')
                    ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')],
            'schedule_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(Schedule::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')],
            'schedule_time_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(ScheduleTime::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')],
            'tickets' => ['required', 'array'],
            'tickets.*.event_ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->whereNull('deleted_at')
            ],
            'tickets.*.quantity' => ['required', 'integer', 'min:1'],
            'tickets.*.valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'promo_code_uuid' => ['nullable', 'uuid', Rule::exists(PromoCode::class, 'uuid')
                ->whereNull('deleted_at')
                ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])],
            'affiliate_code' => ['nullable', 'string', 'max:32'],
            'event_location_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(EventLocation::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->where('is_active', true),
            ],
        ];

        $event = Event::where('uuid', $this->input('event_uuid'))->first();
        if ($event && $event->event_config === Event::EVENT_CONFIGS['SEAT_SELECTION']) {
            $rules['tickets.*.seats'] = ['required', 'array'];
            $rules['tickets.*.seats.*.uuid'] = ['required', 'uuid', 'exists:venue_seats,uuid'];
            $rules['tickets.*.seats.*.row'] = ['required', 'string'];
            $rules['tickets.*.seats.*.seat_no'] = ['required', 'integer'];
            $rules['tickets.*.seats.*.category'] = ['required', 'string'];
            $rules['tickets.*.seats.*.color'] = ['nullable', 'string'];
        }
        return $rules;
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $event = Event::with('eventSection')->find($this->input('event_uuid'));
            if (!$event || $event->eventSection?->name !== EventSection::AMUSEMENT_SECTION) {
                return;
            }
            $tickets = $this->input('tickets');
            if (!is_array($tickets)) {
                return;
            }
            foreach ($tickets as $index => $ticket) {
                $eventTicketUuid = $ticket['event_ticket_uuid'] ?? null;
                if (!$eventTicketUuid) {
                    continue;
                }
                $eventTicket = EventTicket::find($eventTicketUuid);
                if (!$eventTicket || $eventTicket->visit_policy !== 'priority') {
                    continue;
                }
                if (empty($ticket['valid_until'])) {
                    $validator->errors()->add("tickets.{$index}.valid_until", 'Date of Visit is required for this priority ticket.');
                }
            }

            foreach ($tickets as $index => $ticket) {
                if (empty($ticket['valid_until'])) {
                    continue;
                }

                $visitDate = \Carbon\Carbon::parse($ticket['valid_until'])->toDateString();
                if (!$event->isVisitDateBookable($visitDate)) {
                    $validator->errors()->add(
                        "tickets.{$index}.valid_until",
                        "Today's visit date is no longer available. Please choose another date.",
                    );
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
            'temp_transaction_uuid.required' => 'Temp transaction uuid is required.',
            'temp_transaction_uuid.uuid' => 'Temp transaction uuid must be a valid uuid.',
            'temp_transaction_uuid.exists' => 'Temp transaction uuid does not exist.',
            'event_uuid.required' => 'Event uuid is required.',
            'event_uuid.uuid' => 'Event uuid must be a valid uuid.',
            'event_uuid.exists' => 'Event uuid does not exist.',
            'schedule_uuid.required' => 'Schedule uuid is required.',
            'schedule_uuid.uuid' => 'Schedule uuid must be a valid uuid.',
            'schedule_uuid.exists' => 'Schedule uuid does not exist.',
            'schedule_time_uuid.required' => 'Schedule time uuid is required.',
            'schedule_time_uuid.uuid' => 'Schedule time uuid must be a valid uuid.',
            'schedule_time_uuid.exists' => 'Schedule time uuid does not exist.',
            'tickets.required' => 'Tickets are required.',
            'tickets.array' => 'Tickets must be an array.',
            'tickets.*.seats.required' => 'Please choose your seats for the selected tickets.',
            'tickets.*.seats.array' => 'Seats must be an array.',
            'tickets.*.seats.*.uuid.required' => 'Seat uuid is required.',
            'tickets.*.seats.*.uuid.uuid' => 'Seat uuid must be a valid uuid.',
            'tickets.*.seats.*.uuid.exists' => 'Seat uuid does not exist.',
            'tickets.*.seats.*.row.required' => 'Row is required.',
            'tickets.*.seats.*.row.string' => 'Row must be a string.',
            'tickets.*.seats.*.seat_no.required' => 'Seat number is required.',
            'tickets.*.seats.*.seat_no.integer' => 'Seat number must be an integer.',
            'tickets.*.seats.*.category.required' => 'Category is required.',
            'tickets.*.seats.*.category.string' => 'Category must be a string.',
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'temp_transaction_uuid' => $this->route('uuid')
        ]);
    }
}
