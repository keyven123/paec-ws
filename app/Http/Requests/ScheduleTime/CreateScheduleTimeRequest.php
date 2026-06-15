<?php

namespace App\Http\Requests\ScheduleTime;

use App\Models\Schedule;
use App\Models\EventTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateScheduleTimeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schedule_uuid' => [
                'required',
                'uuid',
                Rule::exists(Schedule::class, 'uuid')->whereNull('deleted_at')
            ],
            'time_start' => ['required', 'date_format:H:i:s'],
            'time_end' => ['required', 'date_format:H:i:s'],
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled'])],
            'inherit_event_tickets' => ['nullable', 'boolean'],
            'event_ticket_uuids' => ['nullable', Rule::requiredIf(function () {
                return $this->input('inherit_event_tickets') == true;
            }), 'array'],
            'event_ticket_uuids.*' => [
                'uuid',
                Rule::exists(EventTicket::class, 'uuid')
                    ->whereNull('deleted_at')
                    ->where('schedule_uuid', $this->input('schedule_uuid'))
            ],
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
            'time_end.after' => 'The end time must be after the start time.',
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
            'event_ticket_uuids.required' => 'The event tickets are required when inherit event tickets is true.',
        ];
    }
}
