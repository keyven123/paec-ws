<?php

namespace App\Http\Requests\Customer;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowEventTicketRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'uuid' => [
                'required',
                'uuid',
                Rule::exists(Event::class, 'uuid')
                    ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                    ->whereNull('deleted_at')],
            'schedule_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(Schedule::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->where('event_uuid', $this->input('uuid'))
                    ->whereNull('deleted_at')],
            'schedule_time_uuid' => [
                'nullable',
                'uuid',
                Rule::exists(ScheduleTime::class, 'uuid')
                    ->where('status', GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'])
                    ->where('schedule_uuid', $this->input('schedule_uuid'))
                    ->whereNull('deleted_at')],
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
            'uuid.required' => 'Event uuid is required.',
            'uuid.uuid' => 'Event uuid must be a valid uuid.',
            'uuid.exists' => 'Event uuid does not exist.',
            'schedule_uuid.uuid' => 'Schedule uuid must be a valid uuid.',
            'schedule_uuid.exists' => 'Schedule uuid does not exist.',
            'schedule_time_uuid.required' => 'Schedule time uuid is required.',
            'schedule_time_uuid.uuid' => 'Schedule time uuid must be a valid uuid.',
            'schedule_time_uuid.exists' => 'Schedule time uuid does not exist.',
        ];
    }
}
