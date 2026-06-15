<?php

namespace App\Http\Requests\Ticket;

use App\Constants\GeneralConstants;
use App\Models\EventTicket;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpgradeTicketRequest extends FormRequest
{
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
                Rule::exists(Ticket::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $ticketUuid = $this->route('uuid');
            $ticket = Ticket::query()->where('uuid', $ticketUuid)->first();
            $eventTicketUuid = $this->input('ticket_uuid');
            if (!$ticket || !$eventTicketUuid) {
                return;
            }
            $eventTicket = EventTicket::query()->where('uuid', $eventTicketUuid)->first();
            if (!$eventTicket) {
                return;
            }
            if ($eventTicket->event_uuid !== $ticket->event_uuid) {
                $validator->errors()->add(
                    'ticket_uuid',
                    'The selected ticket type must belong to the same event as this ticket.'
                );
            }
            if ($eventTicket->uuid === $ticket->event_ticket_uuid) {
                $validator->errors()->add('ticket_uuid', 'Choose a different ticket type to upgrade to.');
            }
            if ($eventTicket->status !== GeneralConstants::GENERAL_STATUSES['ACTIVE']) {
                $validator->errors()->add('ticket_uuid', 'The selected ticket type is not active.');
            }
            if ($eventTicket->is_bundle) {
                $validator->errors()->add('ticket_uuid', 'Bundle ticket types cannot be used for upgrades.');
            }
        });
    }
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->route('uuid')
        ]);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
        ];
    }
}
