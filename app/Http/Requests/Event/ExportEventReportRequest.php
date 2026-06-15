<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class ExportEventReportRequest extends FormRequest
{
    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'required_with:end_date', 'date', 'before_or_equal:end_date'],
            'end_date' => ['nullable', 'required_with:start_date', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_date.required_with' => 'Start date is required when end date is provided.',
            'start_date.before_or_equal' => 'Start date must be on or before end date.',
            'end_date.required_with' => 'End date is required when start date is provided.',
            'end_date.after_or_equal' => 'End date must be on or after start date.',
        ];
    }
}
