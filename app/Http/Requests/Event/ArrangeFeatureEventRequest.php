<?php

namespace App\Http\Requests\Event;

use Illuminate\Foundation\Http\FormRequest;

class ArrangeFeatureEventRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array'],
            'events.*.uuid' => ['required', 'uuid', 'exists:events,uuid'],
            'events.*.featured_order' => ['required', 'integer', 'min:0'],
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
            'events.required' => 'Events are required.',
            'events.*.required' => 'Event is required.',
            'events.*.uuid' => 'Event must be a valid uuid.',
            'events.*.uuid.exists' => 'Event does not exist.',
        ];
    }
}
