<?php

namespace App\Http\Requests\TicketSeat;

use App\Models\Ticket;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTicketSeatRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticket_uuid' => [
                'required',
                'uuid',
                Rule::exists(Ticket::class, 'uuid')->whereNull('deleted_at')
            ],
            'venue_seat_uuid' => [
                'required',
                'uuid',
                Rule::exists(VenueSeat::class, 'uuid')->whereNull('deleted_at'),
            ],
            'venue_uuid' => [
                'required',
                'uuid',
                Rule::exists(Venue::class, 'uuid')->whereNull('deleted_at')
            ],
            'col' => ['required', 'string', 'max:10'],
            'row' => ['required', 'integer', 'min:1'],
            'seat_no' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(['bronze', 'silver', 'gold', 'vip', 'svip'])],
            'color' => ['required', Rule::in(['bronze', 'silver', 'gold', 'red', 'green'])],
            'status' => ['nullable', 'string'],
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
            'ticket_uuid.exists' => 'The selected ticket does not exist.',
            'venue_seat_uuid.exists' => 'The selected venue seat does not exist.',
            'venue_seat_uuid.unique' => 'This venue seat is already assigned to this ticket.',
            'category.in' => 'The category must be one of: bronze, silver, gold, vip, svip.',
            'color.in' => 'The color must be one of: bronze, silver, gold, red, green.',
        ];
    }
}
