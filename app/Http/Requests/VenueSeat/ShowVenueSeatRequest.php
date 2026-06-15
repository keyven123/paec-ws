<?php

namespace App\Http\Requests\VenueSeat;

use App\Constants\GeneralConstants;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowVenueSeatRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string'],
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')
                    ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')
            ],
            'schedule_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(Schedule::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')
            ],
            'schedule_time_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(ScheduleTime::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')
            ],
            'category' => ['nullable', 'string'],
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
            'uuid.exists' => 'The selected ticket does not exist.',
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
            'schedule_time_uuid.exists' => 'The selected schedule time does not exist.',
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
            'schedule_time_uuid.exists' => 'The selected schedule time does not exist.',
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
            'schedule_time_uuid.exists' => 'The selected schedule time does not exist.',
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
        ];
    }
}
