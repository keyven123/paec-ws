<?php

namespace App\Http\Requests\ScheduleTime;

use App\Models\Schedule;
use App\Models\ScheduleTime;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduleTimeRequest extends FormRequest
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
                Rule::exists(ScheduleTime::class, 'uuid')
                    ->whereNull('deleted_at')
            ],
            'schedule_uuid' => [
                'sometimes',
                'uuid',
                Rule::exists(Schedule::class, 'uuid')->whereNull('deleted_at')
            ],
            'time_start' => ['sometimes', 'date_format:H:i:s'],
            'time_end' => ['sometimes', 'date_format:H:i:s'],
            'status' => ['nullable', Rule::in(['published', 'draft', 'cancelled'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $scheduleTime = ScheduleTime::query()
                ->where('uuid', $this->input('uuid'))
                ->whereNull('deleted_at')
                ->first();

            if (!$scheduleTime) {
                return;
            }

            $effectiveScheduleUuid = $this->input('schedule_uuid') ?: $scheduleTime->schedule_uuid;
            $schedule = Schedule::query()
                ->where('uuid', $effectiveScheduleUuid)
                ->whereNull('deleted_at')
                ->first();

            if (!$schedule) {
                return;
            }

            $effectiveTimeStart = $this->input('time_start') ?: $scheduleTime->time_start;
            $effectiveTimeEnd = $this->input('time_end') ?: $scheduleTime->time_end;

            if (!$effectiveTimeStart || !$effectiveTimeEnd) {
                return;
            }

            try {
                $startDate = Carbon::parse($schedule->date_from)->startOfDay();
                $endDate = Carbon::parse($schedule->date_to)->startOfDay();

                $startAt = Carbon::parse($startDate->toDateString() . ' ' . $effectiveTimeStart);
                $endAt = Carbon::parse($startDate->toDateString() . ' ' . $effectiveTimeEnd);

                if ($endAt->lessThanOrEqualTo($startAt)) {
                    if ($endDate->greaterThan($startDate)) {
                        $endAt = $endAt->addDay();
                    } else {
                        $validator->errors()->add('time_end', 'The end time must be after the start time.');
                        return;
                    }
                }

                if ($endAt->toDateString() > $endDate->toDateString()) {
                    $validator->errors()->add('time_end', 'The end time exceeds the schedule end date.');
                    return;
                }
            } catch (\Throwable) {
                // If parsing fails, base rules will report invalid formats.
                return;
            }
        });
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
            'schedule_uuid.exists' => 'The selected schedule does not exist.',
        ];
    }
}
