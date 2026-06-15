<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class ListTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['string'],
            'sort' => ['nullable'],
            'per_page' => ['integer'],
            'page' => ['integer'],
            'user_uuid' => ['nullable', 'uuid'],
            'transaction_uuid' => ['nullable', 'uuid'],
            'event_uuid' => ['nullable', 'uuid'],
            'event_ticket_uuid' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
            'is_used' => ['nullable', 'boolean'],
        ];
    }
}
