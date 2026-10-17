<?php

namespace App\Http\Requests\Ticket;

use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendSponsorTicketsRequest extends FormRequest
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
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at'),
            ],
            'event_ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')
                    ->where('event_uuid', $this->input('event_uuid'))
                    ->whereNull('deleted_at'),
            ],
            'email' => ['required', 'email', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $eventTicketUuid = $this->input('event_ticket_uuid');
            if (! $eventTicketUuid) {
                return;
            }

            $eventTicket = EventTicket::query()->where('uuid', $eventTicketUuid)->first();
            if (! $eventTicket || $eventTicket->visit_policy === 'flexible') {
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
            'valid_until' => 'date of visit',
            'event_ticket_uuid' => 'ticket type',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'quantity.required' => 'Quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity cannot exceed 50.',
            'event_ticket_uuid.exists' => 'The selected ticket does not belong to this activity.',
            'valid_until.after_or_equal' => 'Date of visit must be today or a future date.',
        ];
    }
}
