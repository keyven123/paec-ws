<?php

namespace App\Http\Requests\Ticket;

use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ticketUuid = $this->route('uuid');

        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(Ticket::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'user_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(User::class, 'uuid')->whereNull('deleted_at')
            ],
            'transaction_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(Transaction::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_ticket_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')->whereNull('deleted_at')
            ],
            'col' => ['nullable', 'string', 'max:10'],
            'row' => ['nullable', 'string', 'max:10'],
            'status' => ['sometimes', 'string', 'max:50'],
            'attendee_name' => ['sometimes', 'string', 'max:255'],
            'attendee_email' => ['sometimes', 'email', 'max:255'],
            'attendee_contact' => ['nullable', 'string', 'max:20'],
            'qr_code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('tickets', 'qr_code')
                    ->ignore($ticketUuid, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'is_virtual' => ['sometimes', 'boolean'],
        ];
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
            'user_uuid.exists' => 'The selected user does not exist.',
            'transaction_uuid.exists' => 'The selected transaction does not exist.',
            'event_uuid.exists' => 'The selected event does not exist.',
            'event_ticket_uuid.exists' => 'The selected event ticket does not exist.',
            'qr_code.unique' => 'This QR code is already in use.',
        ];
    }
}
