<?php

namespace App\Http\Requests\ScheduleTime;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListScheduleTimeRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled'])],
            'schedule_uuid' => ['nullable', 'uuid'],
            'time_start' => ['nullable', 'time'],
            'time_end' => ['nullable', 'time'],
        ];
    }
}
