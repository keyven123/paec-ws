<?php

namespace App\Http\Requests\Ticket;

use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_uuid' => [
                'required',
                'uuid',
                Rule::exists(AdminUser::class, 'uuid')->whereNull('deleted_at')
            ],
            'transaction_uuid' => [
                'required',
                'uuid',
                Rule::exists(Transaction::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')->whereNull('deleted_at')
            ],
            'event_ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')->whereNull('deleted_at')
            ],
            'col' => ['nullable', 'string', 'max:10'],
            'row' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'string', 'max:50'],
            'attendee_name' => ['required', 'string', 'max:255'],
            'attendee_email' => ['required', 'email', 'max:255'],
            'attendee_contact' => ['nullable', 'string', 'max:20'],
            'qr_code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('tickets', 'qr_code')->whereNull('deleted_at')
            ],
            'is_virtual' => ['nullable', 'boolean'],
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
            'transaction_uuid.exists' => 'The selected transaction does not exist.',
            'event_uuid.exists' => 'The selected event does not exist.',
            'event_ticket_uuid.exists' => 'The selected event ticket does not exist.',
            'qr_code.unique' => 'This QR code is already in use.',
        ];
    }
}
