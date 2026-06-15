<?php

namespace App\Http\Requests\Ticket;

use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VenueSeat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddTicketToUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'user_uuid' => [
                'required',
                'uuid',
                Rule::exists(User::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_location_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(EventLocation::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->where('is_active', true),
            ],
            'event_ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')->whereNull('deleted_at')
            ],
            'venue_seat_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(VenueSeat::class, 'uuid')->whereNull('deleted_at')
            ],
            'type' => ['required', 'string', Rule::in(Ticket::TYPES)],
            'quantity' => ['required', 'integer', 'min:1'],
            'amount' => [
                'sometimes',
                'required_if:type,paid-nr',
                'required_if:type,paid-to-merchant',
                'numeric',
                'min:0',
            ],
            'other_info' => ['nullable', 'array'],
            'other_info.*' => ['array'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $eventTicketUuid = $this->input('event_ticket_uuid');
            if (!$eventTicketUuid) {
                return;
            }

            $eventTicket = EventTicket::query()->where('uuid', $eventTicketUuid)->first();
            if (!$eventTicket || $eventTicket->visit_policy === 'flexible') {
                return;
            }

            if (empty($this->input('valid_until'))) {
                $validator->errors()->add('valid_until', 'Date of visit is required for this ticket.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'amount' => 'total amount paid',
            'valid_until' => 'date of visit',
            'event_location_uuid' => 'location',
        ];
    }
    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_uuid.exists' => 'The selected user does not exist.',
            'event_location_uuid.exists' => 'The selected location does not exist for this activity.',
            'event_uuid.exists' => 'The selected event does not exist.',
            'event_ticket_uuid.exists' => 'The selected event ticket does not exist.',
            'venue_seat_uuid.exists' => 'The selected venue seat does not exist.',
            'col.string' => 'The column must be a string.',
            'row.string' => 'The row must be a string.',
            'quantity.required' => 'The quantity is required.',
            'quantity.integer' => 'The quantity must be an integer.',
            'quantity.min' => 'The quantity must be at least 1.',
            'valid_until.after_or_equal' => 'Date of visit must be today or a future date.',
        ];
    }
}
