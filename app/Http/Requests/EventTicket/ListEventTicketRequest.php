<?php

namespace App\Http\Requests\EventTicket;

use Illuminate\Foundation\Http\FormRequest;

class ListEventTicketRequest extends FormRequest
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
            'event_uuid' => ['nullable', 'uuid'],
            'schedule_time_uuid' => ['nullable', 'uuid'],
            'is_bundle' => ['nullable', 'boolean'],
            'available_from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'available_to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:available_from'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
