<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class ExportOccupiedSeatsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'schedule_uuid' => ['required', 'uuid', 'exists:schedules,uuid'],
            'schedule_time_uuid' => ['required', 'uuid', 'exists:schedule_times,uuid'],
        ];
    }
}
